<?php

declare(strict_types=1);

namespace Qenda\CampaignShare;

use PDO;
use Qenda\CampaignShare\Domain\ClaimOutcome;
use Qenda\CampaignShare\Domain\ClaimService;
use Qenda\CampaignShare\Domain\ExistingKeyDirectory;
use Qenda\CampaignShare\Domain\NullExistingKeyDirectory;
use Qenda\CampaignShare\Domain\PromoterReadModel;
use Qenda\CampaignShare\Domain\UsageRecorder;
use Qenda\CampaignShare\Http\Request;
use Qenda\CampaignShare\Http\Response;
use Qenda\CampaignShare\Http\RouteMap;
use Qenda\CampaignShare\Security\ChallengeStore;
use Qenda\CampaignShare\Security\CsrfStore;
use Qenda\CampaignShare\Security\FailClosedPhonePossessionVerifier;
use Qenda\CampaignShare\Security\OriginGuard;
use Qenda\CampaignShare\Security\PhonePossessionVerifier;
use Qenda\CampaignShare\Security\RateLimiter;
use Qenda\CampaignShare\Security\SessionStore;
use Qenda\CampaignShare\View\PromoterPage;
use Qenda\CampaignShare\View\SharePage;

final class App
{
    private const CSP = "default-src 'none'; script-src 'self'; style-src 'self'; connect-src 'self'; img-src 'none'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

    private function __construct(
        private readonly Config $config,
        private readonly ?PDO $pdo,
        private readonly ?PhonePossessionVerifier $verifier,
        private readonly ?CsrfStore $csrf,
        private readonly ?ChallengeStore $challenges,
        private readonly ?SessionStore $sessions,
        private readonly ?RateLimiter $rates,
        private readonly ?OriginGuard $origin,
        private readonly ?ClaimService $claims,
        private readonly ?UsageRecorder $usage,
        private readonly ?PromoterReadModel $promoters,
        private readonly ?\Closure $clock,
        private readonly string $capability,
        private readonly string $root,
    ) {
    }

    public static function disabled(Config $config): self
    {
        return new self($config->withEnabled(false), null, null, null, null, null, null, null, null, null, null, null, '', dirname(__DIR__));
    }

    public static function create(
        PDO $pdo,
        Config $config,
        PhonePossessionVerifier $verifier,
        ?ExistingKeyDirectory $legacy = null,
        ?\Closure $clock = null,
        ?\Closure $beforeCommit = null,
    ): self {
        if ($config->environment !== 'test' && !$verifier instanceof FailClosedPhonePossessionVerifier) {
            throw new \RuntimeException('non-test environment only accepts the fail-closed verifier');
        }
        if (!$config->enabled) {
            return self::disabled($config);
        }
        $clock = $clock ?? static fn (): int => time();
        $sessions = new SessionStore($pdo, $clock, $config->sessionTtl);
        $usage = new UsageRecorder($pdo, $capability = bin2hex(random_bytes(32)), $clock, true);

        return new self(
            config: $config,
            pdo: $pdo,
            verifier: $verifier,
            csrf: new CsrfStore($pdo, $clock, $config->sessionTtl),
            challenges: new ChallengeStore($pdo, $clock, $config->challengeTtl),
            sessions: $sessions,
            rates: new RateLimiter($pdo, $config->rateMax, $config->rateWindow, $clock),
            origin: new OriginGuard($config->allowedOrigin),
            claims: new ClaimService($pdo, $sessions, $legacy ?? new NullExistingKeyDirectory(), $clock, $beforeCommit),
            usage: $usage,
            promoters: new PromoterReadModel($pdo),
            clock: $clock,
            capability: $capability,
            root: dirname(__DIR__),
        );
    }

    public function handle(Request $request): Response
    {
        if (!$this->config->enabled || $this->pdo === null) {
            return $this->notFound();
        }
        $match = RouteMap::match($request->method, $request->path);
        if ($match === null) {
            return $this->notFound();
        }
        try {
            $this->purgeExpired();

            return $this->dispatch($match['name'], $match['params'], $request);
        } catch (\Throwable) {
            return Response::json(500, ['ok' => false, 'message' => Copy::BUSY]);
        }
    }

    /** @return array{recorded: bool, reason: string} */
    public function recordTrustedEntryForTests(string $publicId, string $phone): array
    {
        $this->assertTestHook();

        return $this->usage()->record($this->capability, $publicId, $phone);
    }

    /** @return array{recorded: bool, reason: string} */
    public function recordUntrustedForTests(string $publicId, string $phone): array
    {
        $this->assertTestHook();

        return $this->usage()->record('client-said-used', $publicId, $phone);
    }

    public function mintPromoterSessionForTests(int $promoterId): string
    {
        $this->assertTestHook();

        return $this->sessions()->mintPromoter($promoterId)['token'];
    }

    /** @param array<string, string> $params */
    private function dispatch(string $name, array $params, Request $request): Response
    {
        return match ($name) {
            'share_page' => $this->sharePage($request),
            'share_js' => Response::raw(200, $this->asset('public/share.js'), 'application/javascript; charset=utf-8'),
            'share_css' => Response::raw(200, $this->asset('public/share.css'), 'text/css; charset=utf-8'),
            'challenge' => $this->challenge($request),
            'verify' => $this->verify($request),
            'claim' => $this->claim($request),
            'go_use' => $this->goUse($request),
            'me' => $this->me($request),
            'promoter_list' => $this->promoterList($request),
            'promoter_search' => $this->promoterSearch($request),
            'promoter_export' => $this->promoterExport($request),
            'promoter_detail' => $this->promoterDetail($request, $params['id'] ?? ''),
            'promoter_page' => $this->promoterPage($request, false),
            'promoter_page_search' => $this->promoterPage($request, true),
            default => $this->notFound(),
        };
    }

    private function sharePage(Request $request): Response
    {
        $invite = (string) ($request->query['invite'] ?? '');
        $promoter = $this->eligiblePromoter($invite);
        $issued = $this->csrf()->issue();
        $periodClosed = false;
        if ($promoter !== null) {
            $periodClosed = (string) $promoter['period_status'] === 'closed';
        }
        $html = (new SharePage())->render([
            'invite_valid' => $promoter !== null,
            'invite' => $promoter['public_code'] ?? '',
            'period_closed' => $periodClosed,
            'csrf_token' => $issued['token'],
            'dialog_open' => false,
        ]);

        return Response::html(200, $html, [$this->cookie('qenda_cs_csrf', $issued['cookie'], $this->config->sessionTtl)], self::CSP);
    }

    private function challenge(Request $request): Response
    {
        $blocked = $this->guardPost($request, 'challenge');
        if ($blocked !== null) {
            return $blocked;
        }
        $payload = $request->json();
        $invite = (string) ($payload['invite'] ?? '');
        $promoter = $this->eligiblePromoter($invite);
        if ($promoter === null) {
            return Response::json(400, ['ok' => false, 'message' => Copy::INVALID_LINK]);
        }
        $phone = PhoneRules::normalize((string) ($payload['phone'] ?? ''));
        if ($phone === null) {
            return Response::json(400, ['ok' => false, 'message' => Copy::FORMAT]);
        }
        $id = $this->challenges()->issue($phone, (string) $promoter['public_code'], (int) $promoter['id']);

        return Response::json(200, ['ok' => true, 'challenge_id' => $id]);
    }

    private function verify(Request $request): Response
    {
        $blocked = $this->guardPost($request, 'verify');
        if ($blocked !== null) {
            return $blocked;
        }
        $payload = $request->json();
        $challenge = $this->challenges()->findOpen((string) ($payload['challenge_id'] ?? ''));
        $proof = (string) ($payload['proof'] ?? '');
        if ($challenge === null || strlen($proof) > 200) {
            return Response::json(400, ['ok' => false, 'message' => Copy::VERIFY_FAIL]);
        }
        $result = $this->verifier()->verifyPossession((string) $challenge['phone'], $proof);
        if (!$result->ok) {
            return Response::json(400, ['ok' => false, 'message' => Copy::VERIFY_FAIL]);
        }
        $this->challenges()->consume((string) $challenge['id']);
        $session = $this->sessions()->mintVisitor(
            (string) $challenge['phone'],
            (string) $challenge['invite_code'],
            (int) $challenge['promoter_id'],
        );

        return Response::json(200, ['ok' => true, 'verified' => true], [
            $this->cookie('qenda_cs_session', $session['token'], $this->config->sessionTtl),
        ]);
    }

    private function claim(Request $request): Response
    {
        $blocked = $this->guardPost($request, 'claim');
        if ($blocked !== null) {
            return $blocked;
        }
        $outcome = $this->claims()->claimForSession((string) ($request->cookies['qenda_cs_session'] ?? ''));

        return $this->outcomeResponse($outcome);
    }

    private function me(Request $request): Response
    {
        $outcome = $this->claims()->viewForSession((string) ($request->cookies['qenda_cs_session'] ?? ''));
        if ($outcome->type === 'denied') {
            return Response::json(400, ['ok' => false, 'message' => Copy::VERIFY_FAIL]);
        }
        if (!$outcome->claimed) {
            return Response::json(200, ['ok' => true, 'verified' => true, 'claimed' => false]);
        }

        return $this->outcomeResponse($outcome);
    }

    private function goUse(Request $request): Response
    {
        $blocked = $this->guardPost($request, 'go-use');
        if ($blocked !== null) {
            return $blocked;
        }
        $outcome = $this->claims()->viewForSession((string) ($request->cookies['qenda_cs_session'] ?? ''));
        if (!$outcome->claimed || $outcome->secret === null) {
            return Response::json(400, ['ok' => false, 'message' => Copy::CLAIM_FIRST]);
        }
        $url = $this->config->publicBase . '/?key=' . rawurlencode($outcome->secret);

        return Response::json(200, [
            'ok' => true,
            'url' => $url,
            'auto_submit' => false,
            'marks_used' => false,
            'phone_in_url' => false,
            'prefill_only' => true,
            'prefill_risk' => 'address_bar_exposes_full_key',
        ]);
    }

    private function promoterList(Request $request): Response
    {
        $promoterId = $this->promoterId($request);
        if ($promoterId === null) {
            return Response::json(400, ['ok' => false, 'message' => Copy::VERIFY_FAIL]);
        }

        return Response::json(200, ['ok' => true, 'items' => $this->promoters()->listForPromoter($promoterId)]);
    }

    private function promoterSearch(Request $request): Response
    {
        $blocked = $this->guardPost($request, 'promoter-search');
        if ($blocked !== null) {
            return $blocked;
        }
        $promoterId = $this->promoterId($request);
        if ($promoterId === null) {
            return Response::json(400, ['ok' => false, 'message' => Copy::VERIFY_FAIL]);
        }
        $result = $this->promoters()->search($promoterId, (string) ($request->json()['q'] ?? ''));
        if (!$result['ok']) {
            return Response::json(400, ['ok' => false, 'message' => Copy::SEARCH_REJECTED]);
        }

        return Response::json(200, ['ok' => true, 'items' => $result['items']]);
    }

    private function promoterExport(Request $request): Response
    {
        $promoterId = $this->promoterId($request);
        if ($promoterId === null) {
            return Response::json(400, ['ok' => false, 'message' => Copy::VERIFY_FAIL]);
        }
        $csv = $this->promoters()->exportCsv($promoterId);
        $headers = Response::raw(200, $csv, 'text/csv; charset=utf-8')->headers;
        $headers['content-disposition'] = 'attachment; filename="claims.csv"';

        return new Response(200, $csv, $headers);
    }

    private function promoterDetail(Request $request, string $id): Response
    {
        $promoterId = $this->promoterId($request);
        if ($promoterId === null) {
            return Response::json(400, ['ok' => false, 'message' => Copy::VERIFY_FAIL]);
        }
        $row = $this->promoters()->detail($promoterId, $id);
        if ($row === null) {
            return Response::json(404, ['ok' => false, 'message' => Copy::NOT_FOUND]);
        }

        return Response::json(200, ['ok' => true, 'item' => $row]);
    }

    private function promoterPage(Request $request, bool $posted): Response
    {
        $promoterId = $this->promoterId($request);
        if ($promoterId === null) {
            return Response::html(400, (new PromoterPage())->render([
                'authorized' => false,
                'message' => Copy::VERIFY_FAIL,
                'items' => [],
                'detail' => null,
                'csrf_token' => '',
            ]), [], self::CSP);
        }
        $issued = $this->csrf()->issue();
        $message = '';
        $items = $this->promoters()->listForPromoter($promoterId);
        if ($posted) {
            $blocked = $this->guardPost($request, 'promoter-page');
            if ($blocked !== null) {
                return $blocked;
            }
            $result = $this->promoters()->search($promoterId, (string) ($request->form['q'] ?? ''));
            if (!$result['ok']) {
                $message = Copy::SEARCH_REJECTED;
            } else {
                $items = $result['items'] ?? [];
            }
        }
        $detail = null;
        $label = (string) ($request->query['label'] ?? '');
        if (!$posted && $label !== '' && !PhoneRules::looksLikePhoneQuery($label)) {
            $detail = $this->promoters()->detail($promoterId, $label);
        }
        $html = (new PromoterPage())->render([
            'authorized' => true,
            'message' => $message,
            'items' => $items,
            'detail' => $detail,
            'csrf_token' => $issued['token'],
        ]);

        return Response::html(200, $html, [$this->cookie('qenda_cs_csrf', $issued['cookie'], $this->config->sessionTtl)], self::CSP);
    }

    private function outcomeResponse(ClaimOutcome $outcome): Response
    {
        if ($outcome->type === 'denied' || !$outcome->claimed || $outcome->secret === null) {
            return Response::json(400, ['ok' => false, 'message' => $outcome->message]);
        }

        return Response::json(200, [
            'ok' => true,
            'outcome' => $outcome->type,
            'title' => $outcome->message,
            'status_label' => $outcome->status === 'used' ? Copy::STATUS_USED : Copy::STATUS_BOUND,
            'key' => $outcome->secret,
            'phone' => $outcome->phone,
            'hint' => Copy::SAVE_HINT,
            'guarantees' => [
                'marks_used_on_this_response' => false,
                'workstation_access' => 'not_guaranteed',
                'usage_when_period_closed' => 'not_recorded',
            ],
        ]);
    }

    private function guardPost(Request $request, string $bucket): ?Response
    {
        if (!$this->origin()->allows($request)) {
            return Response::json(400, ['ok' => false, 'message' => Copy::CSRF]);
        }
        $token = $request->header('x-csrf-token');
        if ($token === null || $token === '') {
            $token = $request->form['csrf_token'] ?? null;
        }
        if (!$this->csrf()->assert($request->cookies['qenda_cs_csrf'] ?? null, $token)) {
            return Response::json(400, ['ok' => false, 'message' => Copy::CSRF]);
        }
        $decision = $this->rates()->check(hash('sha256', $request->remoteAddr . '|' . $bucket));
        if ($decision === 'limited') {
            return Response::json(429, ['ok' => false, 'message' => Copy::RATE]);
        }
        if ($decision !== 'ok') {
            return Response::json(500, ['ok' => false, 'message' => Copy::BUSY]);
        }

        return null;
    }

    /** @return array<string, mixed>|null */
    private function eligiblePromoter(string $invite): ?array
    {
        if (!InviteCode::isWellFormed($invite)) {
            return null;
        }
        $stmt = $this->pdo()->prepare(
            'SELECT p.id, p.public_code, p.eligible, p.period_id, per.status AS period_status
             FROM campaign_share_promoters p
             INNER JOIN campaign_share_periods per ON per.id = p.period_id
             WHERE p.public_code = ? AND p.eligible = 1'
        );
        $stmt->execute([$invite]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function promoterId(Request $request): ?int
    {
        $row = $this->sessions()->findPromoter((string) ($request->cookies['qenda_cs_promoter'] ?? ''));
        if ($row === null) {
            return null;
        }

        return (int) $row['promoter_id'];
    }

    private function purgeExpired(): void
    {
        $now = (int) ($this->clock)();
        $this->pdo()->prepare('DELETE FROM campaign_share_sessions WHERE expires_at <= ?')->execute([$now]);
        $this->pdo()->prepare('DELETE FROM campaign_share_challenges WHERE expires_at <= ?')->execute([$now]);
        $this->pdo()->prepare('DELETE FROM campaign_share_csrf WHERE expires_at <= ?')->execute([$now]);
    }

    private function cookie(string $name, string $value, int $ttl): string
    {
        $parts = [
            rawurlencode($name) . '=' . rawurlencode($value),
            'Path=/',
            'Max-Age=' . $ttl,
            'HttpOnly',
            'SameSite=Strict',
        ];
        if ($this->config->cookieSecure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }

    private function notFound(): Response
    {
        return Response::json(404, ['ok' => false, 'message' => 'not_found']);
    }

    private function asset(string $relative): string
    {
        return (string) file_get_contents($this->root . '/' . $relative);
    }

    private function assertTestHook(): void
    {
        if ($this->config->environment !== 'test' || !$this->config->enabled || $this->usage === null || $this->sessions === null) {
            throw new \RuntimeException('test hook is not available');
        }
    }

    private function usage(): UsageRecorder
    {
        if ($this->usage === null) {
            throw new \RuntimeException('usage recorder is unavailable');
        }

        return $this->usage;
    }

    private function sessions(): SessionStore
    {
        if ($this->sessions === null) {
            throw new \RuntimeException('session store is unavailable');
        }

        return $this->sessions;
    }

    private function csrf(): CsrfStore
    {
        if ($this->csrf === null) {
            throw new \RuntimeException('csrf store is unavailable');
        }

        return $this->csrf;
    }

    private function challenges(): ChallengeStore
    {
        if ($this->challenges === null) {
            throw new \RuntimeException('challenge store is unavailable');
        }

        return $this->challenges;
    }

    private function rates(): RateLimiter
    {
        if ($this->rates === null) {
            throw new \RuntimeException('rate limiter is unavailable');
        }

        return $this->rates;
    }

    private function origin(): OriginGuard
    {
        if ($this->origin === null) {
            throw new \RuntimeException('origin guard is unavailable');
        }

        return $this->origin;
    }

    private function claims(): ClaimService
    {
        if ($this->claims === null) {
            throw new \RuntimeException('claim service is unavailable');
        }

        return $this->claims;
    }

    private function promoters(): PromoterReadModel
    {
        if ($this->promoters === null) {
            throw new \RuntimeException('promoter read model is unavailable');
        }

        return $this->promoters;
    }

    private function verifier(): PhonePossessionVerifier
    {
        if ($this->verifier === null) {
            throw new \RuntimeException('verifier is unavailable');
        }

        return $this->verifier;
    }

    private function pdo(): PDO
    {
        if ($this->pdo === null) {
            throw new \RuntimeException('database is unavailable');
        }

        return $this->pdo;
    }
}
