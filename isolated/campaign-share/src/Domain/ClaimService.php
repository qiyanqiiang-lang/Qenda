<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Domain;

use PDO;
use Qenda\CampaignShare\Copy;
use Qenda\CampaignShare\Security\SessionStore;

final class ClaimService
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly SessionStore $sessions,
        private readonly ExistingKeyDirectory $legacy,
        private readonly \Closure $clock,
        private readonly ?\Closure $beforeCommit = null,
    ) {
    }

    public function viewForSession(string $token): ClaimOutcome
    {
        $session = $this->sessions->findVisitor($token);
        if ($session === null) {
            return $this->denied(Copy::VERIFY_FAIL, 'unverified');
        }
        $phone = (string) $session['phone'];
        $existing = $this->resolveExisting($phone);
        if ($existing === null) {
            return new ClaimOutcome('none', '', null, null, null, null, false, null, false);
        }

        return $this->fromExisting($existing, $phone);
    }

    public function claimForSession(string $token): ClaimOutcome
    {
        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $session = $this->sessions->findVisitor($token);
            if ($session === null) {
                $this->rollback();

                return $this->denied(Copy::VERIFY_FAIL, 'unverified');
            }
            $phone = (string) $session['phone'];
            $existing = $this->resolveExisting($phone);
            if ($existing !== null) {
                $this->pdo->exec('COMMIT');

                return $this->fromExisting($existing, $phone);
            }

            $promoter = $this->promoterById((int) $session['promoter_id']);
            if ($promoter === null || (int) $promoter['eligible'] !== 1) {
                $this->pdo->exec('COMMIT');

                return $this->denied(Copy::INVALID_LINK, 'invalid_invite');
            }
            $period = $this->periodById((int) $promoter['period_id']);
            if ($period === null || (string) $period['status'] !== 'open') {
                $this->pdo->exec('COMMIT');

                return $this->denied(Copy::NOT_OPEN, 'closed');
            }

            $update = $this->pdo->prepare(
                'UPDATE campaign_share_periods SET issued_count = issued_count + 1 WHERE id = ? AND status = ? AND issued_count < max_claims'
            );
            $update->execute([(int) $period['id'], 'open']);
            if ($update->rowCount() !== 1) {
                $this->rollback();

                return $this->denied(Copy::QUOTA, 'quota');
            }

            $now = (int) ($this->clock)();
            $publicId = 'kid_' . bin2hex(random_bytes(8));
            $secret = 'qs_' . bin2hex(random_bytes(16));
            $this->pdo->prepare(
                'INSERT INTO campaign_share_keys (public_id, secret, period_id, promoter_id, bound_phone, pool, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
            )->execute([$publicId, $secret, (int) $period['id'], (int) $promoter['id'], $phone, 'campaign_share_isolated', $now]);
            $keyId = (int) $this->pdo->lastInsertId();
            $placeholder = 'tmp_' . bin2hex(random_bytes(8));
            $this->pdo->prepare(
                'INSERT INTO campaign_share_claims (key_id, promoter_id, period_id, visitor_label, status, claimed_at, used_at, source_code) VALUES (?, ?, ?, ?, ?, ?, NULL, ?)'
            )->execute([$keyId, (int) $promoter['id'], (int) $period['id'], $placeholder, 'claimed_bound', $now, (string) $promoter['public_code']]);
            $claimId = (int) $this->pdo->lastInsertId();
            $this->pdo->prepare('UPDATE campaign_share_claims SET visitor_label = ? WHERE id = ?')
                ->execute(['U' . $claimId, $claimId]);

            if ($this->beforeCommit !== null) {
                ($this->beforeCommit)($this->pdo);
            }
            $this->pdo->exec('COMMIT');

            return new ClaimOutcome('issued', Copy::SUCCESS, $secret, $phone, $publicId, 'claimed_bound', false, null, true);
        } catch (\PDOException $e) {
            $this->rollback();
            if ($this->isUnique($e)) {
                $session = $this->sessions->findVisitor($token);
                if ($session !== null) {
                    $existing = $this->resolveExisting((string) $session['phone']);
                    if ($existing !== null) {
                        return $this->fromExisting($existing, (string) $session['phone']);
                    }
                }
            }
            throw $e;
        } catch (\Throwable $e) {
            $this->rollback();
            throw $e;
        }
    }

    /** @param array{kind: string, secret: string, public_id: string, status: ?string} $existing */
    private function fromExisting(array $existing, string $phone): ClaimOutcome
    {
        $status = $existing['status'] ?? 'claimed_bound';

        return new ClaimOutcome(
            'existing',
            Copy::EXISTING,
            $existing['secret'],
            $phone,
            $existing['public_id'],
            $status,
            false,
            null,
            true,
        );
    }

    private function denied(string $message, string $code): ClaimOutcome
    {
        return new ClaimOutcome('denied', $message, null, null, null, null, false, $code, false);
    }

    /** @return array{kind: string, secret: string, public_id: string, status: ?string}|null */
    private function resolveExisting(string $phone): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT k.secret, k.public_id, k.promoter_id, c.status FROM campaign_share_keys k INNER JOIN campaign_share_claims c ON c.key_id = k.id WHERE k.bound_phone = ?'
        );
        $stmt->execute([$phone]);
        $row = $stmt->fetch();
        if ($row) {
            return [
                'kind' => 'isolated',
                'secret' => (string) $row['secret'],
                'public_id' => (string) $row['public_id'],
                'status' => (string) $row['status'],
            ];
        }
        $legacy = $this->legacy->findByPhone($phone);
        if ($legacy === null) {
            return null;
        }

        return [
            'kind' => 'legacy',
            'secret' => $legacy->secret,
            'public_id' => $legacy->publicId,
            'status' => 'claimed_bound',
        ];
    }

    /** @return array<string, mixed>|null */
    private function promoterById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, public_code, eligible, period_id FROM campaign_share_promoters WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    /** @return array<string, mixed>|null */
    private function periodById(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT id, status, max_claims, issued_count FROM campaign_share_periods WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    private function rollback(): void
    {
        try {
            $this->pdo->exec('ROLLBACK');
        } catch (\Throwable) {
        }
    }

    private function isUnique(\PDOException $e): bool
    {
        $state = (string) $e->getCode();

        return $state === '23000' || str_contains($e->getMessage(), 'UNIQUE');
    }
}
