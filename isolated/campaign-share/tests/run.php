<?php

declare(strict_types=1);

use Qenda\CampaignShare\App;
use Qenda\CampaignShare\AppFactory;
use Qenda\CampaignShare\Config;
use Qenda\CampaignShare\Copy;
use Qenda\CampaignShare\Domain\ArrayExistingKeyDirectory;
use Qenda\CampaignShare\Domain\ExistingKeyDirectory;
use Qenda\CampaignShare\Domain\UsageRecorder;
use Qenda\CampaignShare\Http\Request;
use Qenda\CampaignShare\Http\Response;
use Qenda\CampaignShare\Http\RouteMap;
use Qenda\CampaignShare\Security\FailClosedPhonePossessionVerifier;
use Qenda\CampaignShare\Security\PhonePossessionVerifier;
use Qenda\CampaignShare\Security\VerifierFactory;
use Qenda\CampaignShare\Sqlite;
use Qenda\CampaignShare\Tests\ScriptedVerifier;

require dirname(__DIR__) . '/src/bootstrap.php';
require __DIR__ . '/ScriptedVerifier.php';

final class AssertionFailed extends RuntimeException
{
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new AssertionFailed($message);
    }
}

function packageRoot(): string
{
    return dirname(__DIR__);
}

function repoRoot(): string
{
    return dirname(__DIR__, 3);
}

function db(): PDO
{
    $pdo = Sqlite::open('sqlite::memory:');
    Sqlite::execFile($pdo, packageRoot() . '/sql/sqlite/001_up.sql');

    return $pdo;
}

/** @return array{period_id: int, promoters: array<string, array{id: int, code: string}>} */
function seed(PDO $pdo, string $status = 'open', int $max = 100): array
{
    $now = time();
    $pdo->prepare('INSERT INTO campaign_share_periods (code, status, max_claims, issued_count, created_at) VALUES (?, ?, ?, 0, ?)')
        ->execute(['period-main', $status, $max, $now]);
    $periodId = (int) $pdo->lastInsertId();
    $promoters = [];
    foreach (['A', 'B', 'C'] as $name) {
        $code = 'inv_' . substr(hash('sha256', 'promo-' . $name), 0, 20);
        $pdo->prepare('INSERT INTO campaign_share_promoters (public_code, display_name, eligible, period_id, created_at) VALUES (?, ?, 1, ?, ?)')
            ->execute([$code, '推广者' . $name, $periodId, $now]);
        $promoters[$name] = ['id' => (int) $pdo->lastInsertId(), 'code' => $code];
    }

    return ['period_id' => $periodId, 'promoters' => $promoters];
}

function clockState(): object
{
    return (object) ['now' => time()];
}

/** @param array<string, mixed> $overrides */
function makeApp(
    PDO $pdo,
    ?PhonePossessionVerifier $verifier = null,
    ?ExistingKeyDirectory $legacy = null,
    array $overrides = [],
    ?object $clock = null,
    ?callable $beforeCommit = null,
): App {
    $clock = $clock ?? clockState();
    $closure = static function () use ($clock): int {
        return (int) $clock->now;
    };
    $config = Config::fromArray(array_merge([
        'enabled' => true,
        'environment' => 'test',
        'verifier' => 'fail_closed',
        'allow_test_verifier' => false,
        'allowed_origin' => 'https://qenda.cn',
        'public_base' => 'https://qenda.cn',
        'session_ttl' => 1800,
        'challenge_ttl' => 600,
        'rate_max' => 40,
        'rate_window' => 600,
        'cookie_secure' => true,
    ], $overrides));

    return App::create($pdo, $config, $verifier ?? new ScriptedVerifier(), $legacy, $closure, $beforeCommit);
}

/** @param array<string, string> $query @param array<string, string> $cookies @param array<string, mixed>|null $json @param array<string, string> $headers @param array<string, string> $form */
function req(string $method, string $path, array $query = [], array $cookies = [], ?array $json = null, array $headers = [], array $form = [], bool $origin = true): Request
{
    $headers = array_change_key_case($headers, CASE_LOWER);
    if (strtoupper($method) === 'POST' && $origin && !isset($headers['origin'])) {
        $headers['origin'] = 'https://qenda.cn';
    }

    return new Request(
        strtoupper($method),
        $path,
        $query,
        $headers,
        $json === null ? '' : json_encode($json, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        '127.0.0.1',
        $cookies,
        $form,
    );
}

/** @return array<string, mixed> */
function decoded(Response $response): array
{
    $data = json_decode($response->body, true);
    check(is_array($data), 'expected json, got ' . $response->body);

    return $data;
}

function csrfOf(Response $page): string
{
    check(preg_match('/name="csrf-token" content="([a-f0-9]+)"/', $page->body, $match) === 1, 'csrf meta missing');

    return $match[1];
}

/** @return array{cookies: array<string, string>, csrf: string, page: Response} */
function openInvite(App $app, string $invite): array
{
    $page = $app->handle(req('GET', '/campaign/share.html', ['invite' => $invite]));
    check($page->status === 200, 'share page status ' . $page->status);

    return [
        'cookies' => ['qenda_cs_csrf' => $page->cookies()['qenda_cs_csrf'] ?? ''],
        'csrf' => csrfOf($page),
        'page' => $page,
    ];
}

/** @param array{cookies: array<string, string>, csrf: string} $gate @param array<string, mixed> $json */
function postJson(App $app, string $path, array $gate, array $json, bool $origin = true, array $headers = []): Response
{
    $headers['x-csrf-token'] = $gate['csrf'];

    return $app->handle(req('POST', $path, [], $gate['cookies'], $json, $headers, [], $origin));
}

/** @return array{cookies: array<string, string>, csrf: string, verify: Response, challenge: Response} */
function verifyPhone(App $app, string $invite, string $phone, string $proof = 'test-proof-ok'): array
{
    $gate = openInvite($app, $invite);
    $challenge = postJson($app, '/api/campaign-share/v1/challenge', $gate, ['phone' => $phone, 'invite' => $invite]);
    $challengeBody = decoded($challenge);
    $verify = postJson($app, '/api/campaign-share/v1/verify', $gate, [
        'challenge_id' => (string) ($challengeBody['challenge_id'] ?? ''),
        'proof' => $proof,
        'phone' => '19900001111',
        'user_id' => 999,
    ]);
    if (isset($verify->cookies()['qenda_cs_session'])) {
        $gate['cookies']['qenda_cs_session'] = $verify->cookies()['qenda_cs_session'];
    }

    return $gate + ['verify' => $verify, 'challenge' => $challenge];
}

function claim(App $app, array $gate, array $extra = []): Response
{
    return postJson($app, '/api/campaign-share/v1/claim', $gate, $extra);
}

function scalar(PDO $pdo, string $sql, array $params = []): int
{
    $statement = $pdo->prepare($sql);
    $statement->execute($params);

    return (int) $statement->fetchColumn();
}

function assertNoLeak(string $body, array $phones, array $secrets): void
{
    foreach ($phones as $phone) {
        check(!str_contains($body, $phone), "leaked phone {$phone} in {$body}");
        $masked = substr($phone, 0, 3) . '****' . substr($phone, -4);
        check(!str_contains($body, $masked), "leaked mask {$masked}");
    }
    foreach ($secrets as $secret) {
        check(!str_contains($body, $secret), "leaked secret in {$body}");
    }
    check(!str_contains($body, 'qs_'), "leaked key prefix in {$body}");
    check(preg_match('/\d{3}\*{2,}\d{2,}/', $body) !== 1, "masked pattern in {$body}");
}

/** @param array<string, mixed> $value */
function assertKeysSafe(array $value): void
{
    $banned = ['phone', 'mobile', 'bound_phone', 'bound_phone_masked', 'masked_phone', 'secret', 'key', 'msisdn', 'tel', 'handset'];
    $walker = function (mixed $node) use (&$walker, $banned): void {
        if (!is_array($node)) {
            return;
        }
        foreach ($node as $key => $child) {
            if (is_string($key)) {
                check(!in_array(strtolower($key), $banned, true), 'banned field ' . $key);
            }
            $walker($child);
        }
    };
    $walker($value);
}

function cookieHeader(Response $response, string $name): string
{
    foreach ($response->setCookieHeaders() as $header) {
        if (str_starts_with($header, $name . '=')) {
            return $header;
        }
    }
    throw new AssertionFailed('missing cookie ' . $name);
}

function assertCookieFlags(string $header, string $phone): void
{
    check(str_contains($header, 'HttpOnly'), $header);
    check(str_contains($header, 'Secure'), $header);
    check(str_contains($header, 'SameSite=Strict'), $header);
    check(!str_contains($header, $phone), 'phone in cookie');
}

function test_shipped_defaults_and_web_entry_stay_off(): void
{
    $config = Config::load(packageRoot());
    check($config->enabled === false, 'default config enabled');
    check($config->verifier === 'fail_closed', 'default verifier');
    check($config->environment === 'production', 'default environment');
    $raw = (string) file_get_contents(packageRoot() . '/config/default.php');
    foreach (['password', 'mysql', 'host=', 'ssh', '1panel', 'aliyun'] as $blocked) {
        check(!str_contains(strtolower($raw), $blocked), 'default config contains ' . $blocked);
    }
    $index = (string) file_get_contents(packageRoot() . '/public/index.php');
    foreach (['ScriptedVerifier', 'new PDO', 'mysql:', 'sqlite:', '1panel'] as $blocked) {
        check(!str_contains($index, $blocked), 'index contains ' . $blocked);
    }
    $factory = (string) file_get_contents(packageRoot() . '/src/AppFactory.php');
    check(str_contains($factory, 'withEnabled(false)'), 'web factory must force off');
    $tampered = sys_get_temp_dir() . '/cs-web-' . bin2hex(random_bytes(4));
    mkdir($tampered . '/config', 0700, true);
    file_put_contents($tampered . '/config/default.php', str_replace("'enabled' => false", "'enabled' => true", $raw));
    check(Config::load($tampered)->enabled === true, 'tampered load should be readable as on before web entry');
    $app = AppFactory::fromWeb($tampered);
    $response = $app->handle(req('POST', '/api/campaign-share/v1/claim', [], [], ['phone' => '13800138000']));
    check($response->status === 404, 'tampered web entry status');
    check($response->body === '{"ok":false,"message":"not_found"}', $response->body);
    check($response->setCookieHeaders() === [], 'web entry set a cookie');
}

function test_disabled_app_does_not_touch_database_or_old_routes(): void
{
    $pdo = Sqlite::open('sqlite::memory:');
    $app = App::create($pdo, Config::fromArray([
        'enabled' => false,
        'environment' => 'test',
        'verifier' => 'fail_closed',
    ]), new ScriptedVerifier());
    $paths = [
        ['GET', '/campaign/share.html'],
        ['POST', '/api/campaign-share/v1/challenge'],
        ['POST', '/api/campaign-share/v1/verify'],
        ['POST', '/api/campaign-share/v1/claim'],
        ['POST', '/api/campaign-share/v1/go-use'],
        ['GET', '/api/campaign-share/v1/me'],
        ['GET', '/api/campaign-share/v1/promoter/claims'],
        ['GET', '/'],
        ['POST', '/api/campaign/key-generate.php'],
        ['POST', '/Caishen/api/verify.php'],
        ['POST', '/api/campaign-share/v1/used'],
    ];
    foreach ($paths as [$method, $path]) {
        $response = $app->handle(req($method, $path, [], [], ['phone' => '13800138000', 'used' => true]));
        check($response->status === 404, $method . ' ' . $path . ' ' . $response->status . ' ' . $response->body);
        check(!str_contains($response->body, '系统繁忙'), $path . ' touched a missing schema');
    }
}

function test_verifier_factory_refuses_test_doubles_outside_test_injection(): void
{
    $factory = new VerifierFactory();
    $made = $factory->make(Config::load(packageRoot()));
    check($made instanceof FailClosedPhonePossessionVerifier, 'factory verifier');
    $threw = false;
    try {
        $factory->make(Config::fromArray(['verifier' => 'scripted', 'environment' => 'test', 'allow_test_verifier' => true]));
    } catch (RuntimeException) {
        $threw = true;
    }
    check($threw, 'factory accepted a non-fail-closed name');
    $threw = false;
    try {
        App::create(db(), Config::fromArray(['enabled' => true, 'environment' => 'production']), new ScriptedVerifier());
    } catch (RuntimeException) {
        $threw = true;
    }
    check($threw, 'production create accepted the test double');
    $serve = (string) file_get_contents(packageRoot() . '/scripts/serve-fixture.php');
    check(!str_contains($serve, 'ScriptedVerifier'), 'fixture server names the test double');
}

function test_route_map_is_isolated_and_has_no_client_used_flag(): void
{
    $names = [];
    foreach (RouteMap::all() as $route) {
        $names[] = $route['path'];
        check(!str_contains($route['path'], 'verify.php'), $route['path']);
        check(!str_contains($route['path'], 'codes.php'), $route['path']);
        check(!str_contains($route['path'], 'key-generate'), $route['path']);
        check(!str_ends_with($route['path'], '/used'), $route['path']);
    }
    check(in_array('/api/campaign-share/v1/claim', $names, true), 'claim route missing');
    check(RouteMap::match('POST', '/api/campaign-share/v1/used') === null, 'client used route exists');
    check(RouteMap::match('POST', '/api/campaign-share/v1/promoter/session') === null, 'public promoter mint exists');
    $readModel = (string) file_get_contents(packageRoot() . '/src/Domain/PromoterReadModel.php');
    check(!str_contains($readModel, 'bound_phone'), 'promoter query names a phone column');
    check(!str_contains($readModel, 'secret'), 'promoter query names a secret column');
    check(!str_contains($readModel, 'SELECT *'), 'promoter query selects star');
}

function test_share_page_copy_and_closed_default_structure(): void
{
    $pdo = db();
    $world = seed($pdo);
    $app = makeApp($pdo);
    $opened = openInvite($app, $world['promoters']['A']['code']);
    $html = $opened['page']->body;
    foreach ([
        Copy::BRAND, Copy::TITLE, Copy::INTRO, Copy::VALUE_NOTE, Copy::VALUE_1, Copy::VALUE_2, Copy::VALUE_3,
        Copy::SHARED_BY, Copy::OPEN_CLAIM, Copy::DIALOG_HELP, Copy::CONFIRM, Copy::CANCEL, Copy::PROOF_HELP,
    ] as $copy) {
        check(str_contains($html, $copy), 'missing copy ' . $copy);
    }
    check(str_contains($html, '<dialog id="claim-dialog">'), 'dialog is missing or already open');
    check(!str_contains($html, 'open>'), 'dialog rendered open');
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 0, 'opening the link issued a key');
    foreach (['gtag', 'google-analytics', 'hm.baidu', 'umami', 'mixpanel', '¥', '价格', '充值', '提现', '免费'] as $blocked) {
        check(!str_contains($html, $blocked), 'page contains ' . $blocked);
    }
    check($opened['page']->header('cache-control') === 'no-store, private', 'cache header');
    check($opened['page']->header('referrer-policy') === 'no-referrer', 'referrer header');
    check($opened['page']->header('content-security-policy') !== null && !str_contains((string) $opened['page']->header('content-security-policy'), 'unsafe-inline'), 'csp');
    assertCookieFlags(cookieHeader($opened['page'], 'qenda_cs_csrf'), '13800138000');
    $js = (string) file_get_contents(packageRoot() . '/public/share.js');
    foreach (['localStorage', 'sessionStorage', 'URLSearchParams', 'gtag', 'google-analytics', '?phone=', 'used:true', '"used"'] as $blocked) {
        check(!str_contains($js, $blocked), 'js contains ' . $blocked);
    }
    check(str_contains($js, 'event.preventDefault()'), 'form can submit the phone into the url');
    check(str_contains($js, 'window.location.assign(data.url)'), 'go-use navigation missing');
    check(str_contains($js, 'This click does not mark the key used'), 'go-use comment missing');
}

function test_invalid_invite_does_not_echo_phone_or_unknown_code(): void
{
    $pdo = db();
    $world = seed($pdo);
    $app = makeApp($pdo);
    $phoneInvite = $app->handle(req('GET', '/campaign/share.html', ['invite' => '13800138000']));
    check(str_contains($phoneInvite->body, Copy::INVALID_LINK), 'invalid copy');
    check(!str_contains($phoneInvite->body, '13800138000'), 'echoed phone invite');
    check(!str_contains($phoneInvite->body, 'name="phone"'), 'phone field on invalid link');
    $unknown = 'inv_' . str_repeat('ab', 10);
    $unknownPage = $app->handle(req('GET', '/campaign/share.html', ['invite' => $unknown]));
    check(!str_contains($unknownPage->body, $unknown), 'echoed unknown code');
    $withPhone = $app->handle(req('GET', '/campaign/share.html', [
        'invite' => $world['promoters']['A']['code'],
        'phone' => '13700137000',
    ]));
    check(!str_contains($withPhone->body, '13700137000'), 'echoed query phone');
}

function test_failed_verification_is_identical_and_issues_nothing(): void
{
    $pdo = db();
    $world = seed($pdo);
    $secret = 'manual-secret-value';
    $legacy = new ArrayExistingKeyDirectory([[
        'phone' => '13800138000',
        'secret' => $secret,
        'public_id' => 'old_manual',
        'attribution' => 'manual',
        'source' => 'manual',
    ]]);
    $verifier = new ScriptedVerifier();
    $app = makeApp($pdo, $verifier, $legacy);
    $known = verifyPhone($app, $world['promoters']['A']['code'], '13800138000', 'wrong-proof');
    $unknown = verifyPhone($app, $world['promoters']['A']['code'], '13900139000', 'wrong-proof');
    check($known['verify']->status === 400, 'known status');
    check($known['verify']->body === $unknown['verify']->body, "oracle\n{$known['verify']->body}\n{$unknown['verify']->body}");
    check($known['verify']->body === json_encode(['ok' => false, 'message' => Copy::VERIFY_FAIL], JSON_UNESCAPED_UNICODE), $known['verify']->body);
    assertNoLeak($known['verify']->body, ['13800138000', '13900139000'], [$secret]);
    assertNoLeak($known['challenge']->body, ['13800138000'], [$secret]);
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 0, 'failed verify wrote a key');
    check($legacy->snapshot()[0]['secret'] === $secret, 'legacy mutated');
    $plainPdo = db();
    $plainWorld = seed($plainPdo);
    $plain = verifyPhone(
        makeApp($plainPdo, new FailClosedPhonePossessionVerifier(), $legacy),
        $plainWorld['promoters']['A']['code'],
        '13800138000',
        'test-proof-ok',
    );
    check($plain['verify']->body === $known['verify']->body, 'fail closed differed');
    check(scalar($plainPdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 0, 'fail closed issued a key');
}

function test_format_check_is_not_possession_and_challenge_hides_binding(): void
{
    $pdo = db();
    $world = seed($pdo);
    $verifier = new ScriptedVerifier();
    $legacy = new ArrayExistingKeyDirectory([[
        'phone' => '13800138000', 'secret' => 'manual-secret-value', 'public_id' => 'old_manual', 'attribution' => 'manual', 'source' => 'manual',
    ]]);
    $app = makeApp($pdo, $verifier, $legacy);
    $gate = openInvite($app, $world['promoters']['A']['code']);
    $bad = postJson($app, '/api/campaign-share/v1/challenge', $gate, ['phone' => '+86 13800138000', 'invite' => $world['promoters']['A']['code']]);
    check($bad->body === json_encode(['ok' => false, 'message' => Copy::FORMAT], JSON_UNESCAPED_UNICODE), $bad->body);
    check(!str_contains($bad->body, '13800138000'), 'format error echoed the phone');
    check($verifier->calls === 0, 'format check called the verifier');
    $bound = postJson($app, '/api/campaign-share/v1/challenge', $gate, ['phone' => '13800138000', 'invite' => $world['promoters']['A']['code']]);
    $free = postJson($app, '/api/campaign-share/v1/challenge', $gate, ['phone' => '13900139000', 'invite' => $world['promoters']['A']['code']]);
    $boundData = decoded($bound);
    $freeData = decoded($free);
    unset($boundData['challenge_id'], $freeData['challenge_id']);
    check($boundData === $freeData, 'challenge disclosed binding');
    assertNoLeak($bound->body, ['13800138000'], ['manual-secret-value']);
    check($verifier->calls === 0, 'challenge called the verifier');
    $spaced = verifyPhone($app, $world['promoters']['A']['code'], '136 0013 6000');
    $issued = claim($app, $spaced);
    check(decoded($issued)['phone'] === '13600136000', 'normalized phone was not used');
}

function test_csrf_origin_and_rate_limit_write_nothing_sensitive(): void
{
    $pdo = db();
    $world = seed($pdo);
    $app = makeApp($pdo, null, null, ['rate_max' => 3]);
    $gate = openInvite($app, $world['promoters']['A']['code']);
    $noToken = $app->handle(req('POST', '/api/campaign-share/v1/challenge', [], ['qenda_cs_csrf' => $gate['cookies']['qenda_cs_csrf']], [
        'phone' => '13800138000', 'invite' => $world['promoters']['A']['code'],
    ]));
    check($noToken->body === json_encode(['ok' => false, 'message' => Copy::CSRF], JSON_UNESCAPED_UNICODE), $noToken->body);
    $badOrigin = postJson($app, '/api/campaign-share/v1/challenge', $gate, [
        'phone' => '13800138000', 'invite' => $world['promoters']['A']['code'],
    ], true, ['origin' => 'https://evil.example']);
    check($badOrigin->body === $noToken->body, 'origin mismatch differed');
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_challenges') === 0, 'rejected post wrote a challenge');
    for ($i = 0; $i < 3; $i++) {
        $ok = postJson($app, '/api/campaign-share/v1/challenge', $gate, ['phone' => '1380013800' . $i, 'invite' => $world['promoters']['A']['code']]);
        check($ok->status === 200, 'rate limit tripped early ' . $ok->body);
    }
    $limited = postJson($app, '/api/campaign-share/v1/challenge', $gate, ['phone' => '13800138000', 'invite' => $world['promoters']['A']['code']]);
    check($limited->status === 429, 'expected rate limit');
    check($limited->body === json_encode(['ok' => false, 'message' => Copy::RATE], JSON_UNESCAPED_UNICODE), $limited->body);
    assertNoLeak($limited->body, ['13800138000'], []);
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_challenges') === 3, 'limited request created a challenge');
}

function test_new_claim_is_transactional_and_ignores_client_identity(): void
{
    $pdo = db();
    $world = seed($pdo);
    $app = makeApp($pdo);
    $gate = verifyPhone($app, $world['promoters']['A']['code'], '13800138000');
    check(decoded($gate['verify']) === ['ok' => true, 'verified' => true], $gate['verify']->body);
    assertCookieFlags(cookieHeader($gate['verify'], 'qenda_cs_session'), '13800138000');
    $me = $app->handle(req('GET', '/api/campaign-share/v1/me', [], $gate['cookies']));
    check(decoded($me) === ['ok' => true, 'verified' => true, 'claimed' => false], $me->body);
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 0, 'me issued a key');
    $issued = claim($app, $gate, [
        'phone' => '13900139000',
        'user_id' => 5,
        'promoter_id' => $world['promoters']['C']['id'],
        'used' => true,
    ]);
    $data = decoded($issued);
    check($data['outcome'] === 'issued', $issued->body);
    check($data['title'] === Copy::SUCCESS, $data['title']);
    check($data['status_label'] === Copy::STATUS_BOUND, $data['status_label']);
    check($data['phone'] === '13800138000', 'client phone replaced the session');
    check(str_starts_with((string) $data['key'], 'qs_'), 'key prefix');
    check($data['guarantees']['marks_used_on_this_response'] === false, 'claim marked used');
    check($data['guarantees']['workstation_access'] === 'not_guaranteed', 'access was promised');
    check(scalar($pdo, 'SELECT promoter_id FROM campaign_share_keys') === $world['promoters']['A']['id'], 'attribution followed the body');
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_claims WHERE status = "claimed_bound" AND used_at IS NULL') === 1, 'status was not claimed_bound');
    $again = claim($app, $gate, ['phone' => '13900139000']);
    check(decoded($again)['outcome'] === 'existing', $again->body);
    check(decoded($again)['key'] === $data['key'], 'second claim changed the key');
    check(scalar($pdo, 'SELECT issued_count FROM campaign_share_periods') === 1, 'second claim consumed quota');
    $postPage = $app->handle(req('POST', '/campaign/share.html', [], [], null, [], ['phone' => '13800138000']));
    check($postPage->status === 404, 'form post without js was accepted');
    assertNoLeak($postPage->body, ['13800138000'], [(string) $data['key']]);
}

function test_cross_promoter_legacy_period_closure_and_quota(): void
{
    $pdo = db();
    $world = seed($pdo, 'open', 1);
    $legacy = new ArrayExistingKeyDirectory([[
        'phone' => '13600136000', 'secret' => 'manual-secret-value', 'public_id' => 'old_manual', 'attribution' => 'manual-poster', 'source' => 'manual',
    ]]);
    $app = makeApp($pdo, null, $legacy);
    $first = verifyPhone($app, $world['promoters']['A']['code'], '13800138000');
    $issued = decoded(claim($app, $first));
    $second = verifyPhone($app, $world['promoters']['B']['code'], '13800138000');
    $existing = decoded(claim($app, $second, ['promoter_id' => $world['promoters']['B']['id']]));
    check($existing['outcome'] === 'existing' && $existing['title'] === Copy::EXISTING, json_encode($existing, JSON_UNESCAPED_UNICODE));
    check($existing['key'] === $issued['key'], 'cross promoter reissued');
    check(!array_key_exists('promoter_id', $existing), 'visitor payload names a promoter');
    check(scalar($pdo, 'SELECT promoter_id FROM campaign_share_keys WHERE bound_phone = "13800138000"') === $world['promoters']['A']['id'], 'attribution moved');
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 1, 'cross promoter created a key');

    $old = verifyPhone($app, $world['promoters']['C']['code'], '13600136000');
    $legacyResult = decoded(claim($app, $old));
    check($legacyResult['key'] === 'manual-secret-value', 'legacy key was not returned');
    check($legacyResult['title'] === Copy::EXISTING, 'legacy title');
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 1, 'legacy phone entered the new pool');
    check($legacy->snapshot()[0]['attribution'] === 'manual-poster', 'legacy attribution changed');

    $pdo->prepare('INSERT INTO campaign_share_periods (code, status, max_claims, issued_count, created_at) VALUES ("period-2", "open", 5, 0, ?)')->execute([time()]);
    $period2 = (int) $pdo->lastInsertId();
    $codeD = 'inv_' . substr(hash('sha256', 'promo-D'), 0, 20);
    $pdo->prepare('INSERT INTO campaign_share_promoters (public_code, display_name, eligible, period_id, created_at) VALUES (?, "推广者D", 1, ?, ?)')->execute([$codeD, $period2, time()]);
    $cross = verifyPhone($app, $codeD, '13800138000');
    $crossResult = decoded(claim($app, $cross));
    check($crossResult['key'] === $issued['key'], 'cross period reissued');
    check(scalar($pdo, 'SELECT period_id FROM campaign_share_keys WHERE bound_phone = "13800138000"') === $world['period_id'], 'period moved');
    check(scalar($pdo, 'SELECT issued_count FROM campaign_share_periods WHERE id = ' . $period2) === 0, 'new period consumed quota');

    $quotaGate = verifyPhone($app, $world['promoters']['A']['code'], '13700137000');
    $quota = claim($app, $quotaGate);
    check(decoded($quota)['message'] === Copy::QUOTA, $quota->body);
    check(decoded($app->handle(req('GET', '/api/campaign-share/v1/me', [], $first['cookies'])))['key'] === $issued['key'], 'existing user lost the key at quota');

    $pdo->prepare('UPDATE campaign_share_periods SET status = "closed" WHERE id = ?')->execute([$world['period_id']]);
    $closedExisting = decoded(claim($app, $first));
    check($closedExisting['key'] === $issued['key'], 'closure hid the original key');
    check($closedExisting['guarantees']['usage_when_period_closed'] === 'not_recorded', 'closure promised usage');
    $closedNew = verifyPhone($app, $world['promoters']['A']['code'], '15000150000');
    $closedDeny = claim($app, $closedNew);
    check(decoded($closedDeny)['message'] === Copy::NOT_OPEN, $closedDeny->body);
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys WHERE bound_phone = "15000150000"') === 0, 'closure issued a key');
    $page = openInvite($app, $world['promoters']['A']['code']);
    check(str_contains($page['page']->body, Copy::CLOSURE_BANNER), 'closure banner missing');
    check(str_contains($page['page']->body, 'name="phone"'), 'closure removed retrieval');
}

function test_usage_requires_trusted_open_period_signal(): void
{
    $pdo = db();
    $world = seed($pdo);
    $app = makeApp($pdo);
    $gate = verifyPhone($app, $world['promoters']['A']['code'], '13800138000');
    $issued = decoded(claim($app, $gate, ['used' => true]));
    $go = postJson($app, '/api/campaign-share/v1/go-use', $gate, ['used' => true, 'phone' => '13900139000']);
    $goData = decoded($go);
    $parts = parse_url((string) $goData['url']);
    parse_str((string) ($parts['query'] ?? ''), $query);
    check(($parts['host'] ?? '') === 'qenda.cn', 'host');
    check(($query['key'] ?? '') === $issued['key'], 'prefill key');
    check(!isset($query['phone']), 'phone entered the url');
    check($goData['auto_submit'] === false && $goData['marks_used'] === false && $goData['phone_in_url'] === false, json_encode($goData));
    check($goData['prefill_risk'] === 'address_bar_exposes_full_key', 'risk was hidden');
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_claims WHERE status = "claimed_bound" AND used_at IS NULL') === 1, 'go-use marked used');
    $missing = $app->handle(req('POST', '/api/campaign-share/v1/used', [], $gate['cookies'], ['used' => true]));
    check($missing->status === 404, 'client used route worked');
    $publicId = (string) $pdo->query('SELECT public_id FROM campaign_share_keys')->fetchColumn();
    $untrusted = $app->recordUntrustedForTests($publicId, '13800138000');
    check($untrusted['reason'] === 'untrusted_signal', json_encode($untrusted));
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_claims WHERE status = "used"') === 0, 'untrusted signal wrote used');
    $trusted = $app->recordTrustedEntryForTests($publicId, '13800138000');
    check($trusted['reason'] === 'recorded', json_encode($trusted));
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_claims WHERE status = "used" AND used_at IS NOT NULL') === 1, 'trusted signal did not record');
    $pdo->prepare('UPDATE campaign_share_periods SET status = "closed"')->execute();
    $pdo->prepare('UPDATE campaign_share_claims SET status = "claimed_bound", used_at = NULL')->execute();
    $closed = $app->recordTrustedEntryForTests($publicId, '13800138000');
    check($closed['reason'] === 'period_closed_usage_not_recorded', json_encode($closed));
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_claims WHERE used_at IS NULL') === 1, 'closed period recorded used');
    $mismatch = $app->recordTrustedEntryForTests($publicId, '13900139000');
    check($mismatch['reason'] === 'phone_mismatch', json_encode($mismatch));
    $disabled = new UsageRecorder($pdo, 'cap', static fn (): int => time(), false);
    check($disabled->record('cap', $publicId, '13800138000')['reason'] === 'feature_disabled_noop', 'disabled recorder wrote');
    check($app->recordTrustedEntryForTests('missing', '13800138000')['reason'] === 'not_campaign_key', 'legacy id was treated as campaign usage');
}

function test_promoter_surfaces_have_no_phone_and_no_foreign_rows(): void
{
    $pdo = db();
    $world = seed($pdo);
    $app = makeApp($pdo);
    $gate = verifyPhone($app, $world['promoters']['A']['code'], '13800138000');
    $issued = decoded(claim($app, $gate));
    $tokenA = $app->mintPromoterSessionForTests($world['promoters']['A']['id']);
    $tokenB = $app->mintPromoterSessionForTests($world['promoters']['B']['id']);
    $listA = $app->handle(req('GET', '/api/campaign-share/v1/promoter/claims', [], ['qenda_cs_promoter' => $tokenA]));
    $listB = $app->handle(req('GET', '/api/campaign-share/v1/promoter/claims', [], ['qenda_cs_promoter' => $tokenB]));
    $dataA = decoded($listA);
    $dataB = decoded($listB);
    check(count($dataA['items']) === 1, 'promoter A list');
    check($dataB['items'] === [], 'promoter B saw a foreign claim');
    check(array_keys($dataA['items'][0]) === ['visitor_label', 'key_public_id', 'status', 'status_label', 'claimed_at', 'used_at', 'source_code'], 'fields ' . implode(',', array_keys($dataA['items'][0])));
    assertKeysSafe($dataA);
    assertNoLeak($listA->body, ['13800138000'], [(string) $issued['key']]);
    check($dataA['items'][0]['status_label'] === Copy::STATUS_BOUND, 'status label');
    check($dataA['items'][0]['used_at'] === null, 'used time filled early');
    $label = (string) $dataA['items'][0]['visitor_label'];
    $publicId = (string) $dataA['items'][0]['key_public_id'];
    $detail = $app->handle(req('GET', '/api/campaign-share/v1/promoter/claims/' . rawurlencode($label), [], ['qenda_cs_promoter' => $tokenA]));
    assertNoLeak($detail->body, ['13800138000'], [(string) $issued['key']]);
    $foreign = $app->handle(req('GET', '/api/campaign-share/v1/promoter/claims/' . rawurlencode($label), [], ['qenda_cs_promoter' => $tokenB]));
    $missing = $app->handle(req('GET', '/api/campaign-share/v1/promoter/claims/U99999', [], ['qenda_cs_promoter' => $tokenB]));
    check($foreign->body === $missing->body, "idor oracle\n{$foreign->body}\n{$missing->body}");
    $phoneDetail = $app->handle(req('GET', '/api/campaign-share/v1/promoter/claims/13800138000', [], ['qenda_cs_promoter' => $tokenA]));
    assertNoLeak($phoneDetail->body, ['13800138000'], [(string) $issued['key']]);
    $gateB = openInvite($app, $world['promoters']['B']['code']);
    $searchPhone = postJson($app, '/api/campaign-share/v1/promoter/claims/search', [
        'cookies' => ['qenda_cs_csrf' => $gateB['cookies']['qenda_cs_csrf'], 'qenda_cs_promoter' => $tokenA],
        'csrf' => $gateB['csrf'],
    ], ['q' => '13800138000']);
    check($searchPhone->body === json_encode(['ok' => false, 'message' => Copy::SEARCH_REJECTED], JSON_UNESCAPED_UNICODE), $searchPhone->body);
    $masked = postJson($app, '/api/campaign-share/v1/promoter/claims/search', [
        'cookies' => ['qenda_cs_csrf' => $gateB['cookies']['qenda_cs_csrf'], 'qenda_cs_promoter' => $tokenA],
        'csrf' => $gateB['csrf'],
    ], ['q' => '138****8000']);
    check($masked->body === $searchPhone->body, 'masked search differed');
    $searchId = postJson($app, '/api/campaign-share/v1/promoter/claims/search', [
        'cookies' => ['qenda_cs_csrf' => $gateB['cookies']['qenda_cs_csrf'], 'qenda_cs_promoter' => $tokenA],
        'csrf' => $gateB['csrf'],
    ], ['q' => $publicId]);
    check(count(decoded($searchId)['items']) === 1, 'public id search');
    $injected = postJson($app, '/api/campaign-share/v1/promoter/claims/search', [
        'cookies' => ['qenda_cs_csrf' => $gateB['cookies']['qenda_cs_csrf'], 'qenda_cs_promoter' => $tokenB],
        'csrf' => $gateB['csrf'],
    ], ['q' => "' OR 1=1 --"]);
    check(decoded($injected)['items'] === [], 'injection returned rows');
    $export = $app->handle(req('GET', '/api/campaign-share/v1/promoter/export', [], ['qenda_cs_promoter' => $tokenA]));
    check(str_contains((string) $export->header('content-type'), 'text/csv'), 'export type');
    check(str_contains((string) $export->header('content-disposition'), 'claims.csv'), 'export name');
    assertNoLeak($export->body, ['13800138000'], [(string) $issued['key']]);
    check(str_contains($export->body, '用户序号'), 'export header');
    check(!str_contains($export->body, '手机'), 'export says phone');
    $visitorOnPromoter = $app->handle(req('GET', '/api/campaign-share/v1/promoter/claims', [], ['qenda_cs_session' => $gate['cookies']['qenda_cs_session']]));
    assertNoLeak($visitorOnPromoter->body, ['13800138000'], [(string) $issued['key']]);
    check(decoded($visitorOnPromoter)['message'] === Copy::VERIFY_FAIL, $visitorOnPromoter->body);
    $page = $app->handle(req('GET', '/campaign/promoter.html', ['label' => $label, 'q' => '13800138000', 'id' => '13800138000'], ['qenda_cs_promoter' => $tokenA]));
    assertNoLeak($page->body, ['13800138000'], [(string) $issued['key']]);
    check(str_contains($page->body, $label), 'promoter page hid the label');
    $posted = $app->handle(req('POST', '/campaign/promoter.html', [], [
        'qenda_cs_promoter' => $tokenA,
        'qenda_cs_csrf' => $page->cookies()['qenda_cs_csrf'],
    ], null, ['x-csrf-token' => csrfOf($page)], ['q' => '138****8000', 'csrf_token' => csrfOf($page)]));
    check(str_contains($posted->body, Copy::SEARCH_REJECTED), $posted->body);
    assertNoLeak($posted->body, ['13800138000'], [(string) $issued['key']]);
    $publicIdSearchAsB = postJson($app, '/api/campaign-share/v1/promoter/claims/search', [
        'cookies' => ['qenda_cs_csrf' => $page->cookies()['qenda_cs_csrf'], 'qenda_cs_promoter' => $tokenB],
        'csrf' => csrfOf($page),
    ], ['q' => $publicId]);
    check(decoded($publicIdSearchAsB)['items'] === [], 'foreign public id was visible');
    $mint = $app->handle(req('POST', '/api/campaign-share/v1/promoter/session', [], [], ['promoter_id' => $world['promoters']['C']['id']]));
    check($mint->status === 404, 'mint route');
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_sessions WHERE purpose = "promoter"') === 2, 'public mint created a session');
}

function test_claim_failure_rolls_back_and_hides_exception_text(): void
{
    $pdo = db();
    $world = seed($pdo);
    $app = makeApp($pdo, null, null, [], null, static function (): void {
        throw new RuntimeException('leak 13800138009 qs_should_not_escape');
    });
    $gate = verifyPhone($app, $world['promoters']['A']['code'], '13800138009');
    $response = claim($app, $gate);
    check($response->status === 500, (string) $response->status);
    check($response->body === json_encode(['ok' => false, 'message' => Copy::BUSY], JSON_UNESCAPED_UNICODE), $response->body);
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 0, 'rolled back key remained');
    check(scalar($pdo, 'SELECT issued_count FROM campaign_share_periods') === 0, 'quota remained incremented');
}

function test_same_phone_and_quota_concurrency(): void
{
    $path = sys_get_temp_dir() . '/cs-' . bin2hex(random_bytes(6)) . '.sqlite';
    $pdo = Sqlite::open('sqlite:' . $path);
    Sqlite::execFile($pdo, packageRoot() . '/sql/sqlite/001_up.sql');
    $world = seed($pdo, 'open', 10);
    $app = makeApp($pdo);
    $one = verifyPhone($app, $world['promoters']['A']['code'], '13800138000');
    $two = verifyPhone($app, $world['promoters']['B']['code'], '13800138000');
    $tokenOne = $one['cookies']['qenda_cs_session'];
    $tokenTwo = $two['cookies']['qenda_cs_session'];
    unset($app, $one, $two, $pdo);
    gc_collect_cycles();
    $results = awaitWorkers($path, [$tokenOne, $tokenTwo]);
    $secrets = [];
    foreach ($results as $result) {
        check(in_array($result['type'], ['issued', 'existing'], true), json_encode($result, JSON_UNESCAPED_UNICODE));
        $secrets[] = $result['secret'];
    }
    check($secrets[0] === $secrets[1], 'concurrent claims diverged');
    $pdo = Sqlite::open('sqlite:' . $path);
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 1, 'concurrent double issue');
    check(scalar($pdo, 'SELECT issued_count FROM campaign_share_periods') === 1, 'concurrent quota');
    $winner = scalar($pdo, 'SELECT promoter_id FROM campaign_share_keys');
    check(
        $winner === $world['promoters']['A']['id'] || $winner === $world['promoters']['B']['id'],
        'concurrent attribution left the verified promoters'
    );

    $quotaPath = sys_get_temp_dir() . '/cs-' . bin2hex(random_bytes(6)) . '.sqlite';
    $quotaDb = Sqlite::open('sqlite:' . $quotaPath);
    Sqlite::execFile($quotaDb, packageRoot() . '/sql/sqlite/001_up.sql');
    $quotaWorld = seed($quotaDb, 'open', 1);
    $quotaApp = makeApp($quotaDb);
    $left = verifyPhone($quotaApp, $quotaWorld['promoters']['A']['code'], '13800138000');
    $right = verifyPhone($quotaApp, $quotaWorld['promoters']['A']['code'], '13900139000');
    $leftToken = $left['cookies']['qenda_cs_session'];
    $rightToken = $right['cookies']['qenda_cs_session'];
    unset($quotaApp, $left, $right, $quotaDb);
    gc_collect_cycles();
    $quotaResults = awaitWorkers($quotaPath, [$leftToken, $rightToken]);
    $types = array_column($quotaResults, 'type');
    sort($types);
    check($types === ['denied', 'issued'], 'quota race types ' . json_encode($quotaResults, JSON_UNESCAPED_UNICODE));
    $denied = $quotaResults[0]['type'] === 'denied' ? $quotaResults[0] : $quotaResults[1];
    check($denied['message'] === Copy::QUOTA, $denied['message']);
    $quotaDb = Sqlite::open('sqlite:' . $quotaPath);
    check(scalar($quotaDb, 'SELECT COUNT(*) FROM campaign_share_keys') === 1, 'quota race issued more than one');
    check(scalar($quotaDb, 'SELECT issued_count FROM campaign_share_periods') === 1, 'quota race counter');
}

/** @param list<string> $tokens @return list<array<string, mixed>> */
function awaitWorkers(string $path, array $tokens): array
{
    $running = [];
    foreach ($tokens as $token) {
        $pipes = [];
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/claim_worker.php', $path, $token],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        check(is_resource($process), 'worker did not start');
        $running[] = [$process, $pipes];
    }
    $results = [];
    foreach ($running as [$process, $pipes]) {
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
        check($code === 0, 'worker failed ' . $stderr . $stdout);
        $decoded = json_decode((string) $stdout, true);
        check(is_array($decoded), 'worker json ' . $stdout);
        $results[] = $decoded;
    }

    return $results;
}

function test_migration_reversibility_and_rollback_keep_issued_keys(): void
{
    $pdo = db();
    $world = seed($pdo);
    $app = makeApp($pdo);
    $gate = verifyPhone($app, $world['promoters']['A']['code'], '13800138000');
    claim($app, $gate);
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 1, 'setup key');
    $root = sys_get_temp_dir() . '/cs-root-' . bin2hex(random_bytes(4));
    mkdir($root . '/config', 0700, true);
    $default = (string) file_get_contents(packageRoot() . '/config/default.php');
    file_put_contents($root . '/config/default.php', str_replace("'enabled' => false", "'enabled' => true", $default));
    file_put_contents($root . '/config/local-enable.php', "<?php return ['enabled' => true];\n");
    check(Config::load($root)->enabled === true, 'fixture root did not load as enabled');
    $dry = runPhp(packageRoot() . '/scripts/rollback.php', ['--root=' . $root]);
    check($dry['code'] === 0, $dry['err']);
    check(!is_file($root . '/config/force-off.flag'), 'dry-run wrote the flag');
    check(is_file($root . '/config/local-enable.php'), 'dry-run deleted local enable');
    $applied = runPhp(packageRoot() . '/scripts/rollback.php', ['--root=' . $root, '--apply']);
    check($applied['code'] === 0, $applied['err']);
    check(is_file($root . '/config/force-off.flag'), 'flag missing');
    check(!is_file($root . '/config/local-enable.php'), 'local enable remained');
    check(Config::load($root)->enabled === false, 'force-off did not win');
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 1, 'flag rollback deleted a key');
    $refused = runPhp(packageRoot() . '/scripts/rollback.php', ['--root=' . $root, '--database=sqlite:/tmp/x.sqlite']);
    check($refused['code'] === 2, 'database argument was accepted');
    $unsafe = runPhp(packageRoot() . '/scripts/rollback.php', ['--root=/opt/1panel/www', '--apply']);
    check($unsafe['code'] === 2, 'unsafe root was accepted');
    $rollbackSource = (string) file_get_contents(packageRoot() . '/scripts/rollback.php');
    foreach (['new PDO', 'mysqli_', 'curl_exec', 'ssh2_'] as $blocked) {
        check(!str_contains($rollbackSource, $blocked), 'rollback script contains ' . $blocked);
    }

    Sqlite::execFile($pdo, packageRoot() . '/sql/sqlite/001_down.sql');
    $left = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name LIKE 'campaign_share_%'")->fetchAll();
    check($left === [], 'down left tables ' . json_encode($left));
    Sqlite::execFile($pdo, packageRoot() . '/sql/sqlite/001_up.sql');
    check(scalar($pdo, 'SELECT COUNT(*) FROM campaign_share_keys') === 0, 're-up restored deleted keys');
    foreach (['sqlite', 'mysql'] as $dialect) {
        $up = (string) file_get_contents(packageRoot() . "/sql/{$dialect}/001_up.sql");
        $down = (string) file_get_contents(packageRoot() . "/sql/{$dialect}/001_down.sql");
        foreach (['sys_access_keys', 'campaign_access_keys', 'campaign_users', 'verify.php', 'codes.php', 'INSERT INTO'] as $blocked) {
            check(!str_contains($up, $blocked), $dialect . ' up contains ' . $blocked);
        }
        check(!str_contains($up, 'DROP TABLE'), $dialect . ' up drops tables');
        preg_match_all('/DROP TABLE IF EXISTS (campaign_share_[a-z_]+)/', $down, $drops);
        check($drops[1] !== [], $dialect . ' down has no drops');
        foreach ($drops[1] as $table) {
            check(str_starts_with($table, 'campaign_share_'), $table);
        }
    }
}

function test_hash_guard_readme_and_forbidden_files(): void
{
    check(md5_file(repoRoot() . '/README.md') === '64b9ebb9b196612b9ad93859faa24385', 'README changed');
    $guard = runPhp(packageRoot() . '/scripts/hash-guard.php', []);
    check($guard['code'] === 0, $guard['out'] . $guard['err']);
    check(str_contains($guard['out'], 'Caishen/api/verify.php ABSENT'), $guard['out']);
    check(str_contains($guard['out'], 'fcda5b23af14045083e4b6fa57d6d792'), 'verify anchor missing');
    check(str_contains($guard['out'], '064bab4092bb9d294c763e67e52b9a7c'), 'codes anchor missing');
    check(str_contains($guard['out'], 'production_not_contacted'), $guard['out']);
    $check = runPhp(packageRoot() . '/scripts/serve-fixture.php', ['--check', '/opt/1panel/www/a.sqlite']);
    check($check['code'] === 2, 'fixture path guard accepted 1panel');
    $safe = sys_get_temp_dir() . '/cs-safe-' . bin2hex(random_bytes(3)) . '.sqlite';
    $ok = runPhp(packageRoot() . '/scripts/serve-fixture.php', ['--check', $safe]);
    check($ok['code'] === 0, $ok['err']);
    $mysql = false;
    try {
        Sqlite::open('mysql:host=127.0.0.1;dbname=qenda');
    } catch (InvalidArgumentException) {
        $mysql = true;
    }
    check($mysql, 'mysql dsn was accepted');
}

function test_front_controller_server_stays_not_found(): void
{
    $port = 18000 + random_int(0, 2000);
    $public = packageRoot() . '/public';
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $public, $public . '/index.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        packageRoot()
    );
    check(is_resource($process), 'server did not start');
    try {
        $seen = false;
        $body = '';
        $http = stream_context_create(['http' => ['ignore_errors' => true]]);
        for ($i = 0; $i < 40; $i++) {
            $body = (string) @file_get_contents('http://127.0.0.1:' . $port . '/campaign/share.html?invite=inv_aaaaaaaaaaaaaaaaaaaa&phone=13800138000', false, $http);
            if ($body !== '') {
                $seen = true;
                break;
            }
            usleep(100000);
        }
        check($seen, 'server did not answer');
        check($body === '{"ok":false,"message":"not_found"}', $body);
        check(!str_contains($body, '13800138000'), 'server echoed a phone');
        foreach (['/', '/Caishen/api/verify.php', '/api/campaign/key-generate.php', '/api/campaign-share/v1/claim'] as $path) {
            $context = stream_context_create(['http' => ['method' => 'POST', 'ignore_errors' => true, 'header' => "Content-Type: application/json\r\n", 'content' => '{"phone":"13800138000","used":true}']]);
            $answered = (string) file_get_contents('http://127.0.0.1:' . $port . $path, false, $context);
            check($answered === '{"ok":false,"message":"not_found"}', $path . ' ' . $answered);
        }
    } finally {
        proc_terminate($process);
        proc_close($process);
    }
}

function test_repository_diff_stays_inside_the_isolated_tree(): void
{
    $repo = repoRoot();
    $status = (string) shell_exec('git -C ' . escapeshellarg($repo) . ' status --porcelain=v1 --untracked-files=all');
    $diff = (string) shell_exec('git -C ' . escapeshellarg($repo) . ' diff --name-only main');
    $paths = [];
    foreach (preg_split('/\R/', $status) ?: [] as $line) {
        if ($line === '') {
            continue;
        }
        $paths[] = trim(substr($line, 3));
    }
    foreach (preg_split('/\R/', trim($diff)) ?: [] as $line) {
        if ($line !== '') {
            $paths[] = trim($line);
        }
    }
    foreach ($paths as $path) {
        foreach (explode(' -> ', $path) as $one) {
            $one = trim($one);
            check(
                str_starts_with($one, 'isolated/campaign-share/') || str_starts_with($one, 'reports/campaign-share/'),
                'path outside the isolated change set: ' . $one
            );
        }
    }
}

/** @param list<string> $args @return array{code: int, out: string, err: string} */
function runPhp(string $script, array $args): array
{
    $pipes = [];
    $process = proc_open(
        array_merge([PHP_BINARY, $script], $args),
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => (string) $out, 'err' => (string) $err];
}

$tests = [
    'test_shipped_defaults_and_web_entry_stay_off',
    'test_disabled_app_does_not_touch_database_or_old_routes',
    'test_verifier_factory_refuses_test_doubles_outside_test_injection',
    'test_route_map_is_isolated_and_has_no_client_used_flag',
    'test_share_page_copy_and_closed_default_structure',
    'test_invalid_invite_does_not_echo_phone_or_unknown_code',
    'test_failed_verification_is_identical_and_issues_nothing',
    'test_format_check_is_not_possession_and_challenge_hides_binding',
    'test_csrf_origin_and_rate_limit_write_nothing_sensitive',
    'test_new_claim_is_transactional_and_ignores_client_identity',
    'test_cross_promoter_legacy_period_closure_and_quota',
    'test_usage_requires_trusted_open_period_signal',
    'test_promoter_surfaces_have_no_phone_and_no_foreign_rows',
    'test_claim_failure_rolls_back_and_hides_exception_text',
    'test_same_phone_and_quota_concurrency',
    'test_migration_reversibility_and_rollback_keep_issued_keys',
    'test_hash_guard_readme_and_forbidden_files',
    'test_front_controller_server_stays_not_found',
    'test_repository_diff_stays_inside_the_isolated_tree',
];

$failed = 0;
foreach ($tests as $name) {
    try {
        $name();
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failed++;
        echo "FAIL {$name}\n{$e->getMessage()}\n{$e->getFile()}:{$e->getLine()}\n";
    }
}
echo $failed === 0 ? 'ALL PASS ' . count($tests) . "\n" : "FAILED {$failed}\n";
exit($failed === 0 ? 0 : 1);
