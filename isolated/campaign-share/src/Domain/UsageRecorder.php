<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Domain;

use PDO;

final class UsageRecorder
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $capability,
        private readonly \Closure $clock,
        private readonly bool $enabled,
    ) {
    }

    /** @return array{recorded: bool, reason: string} */
    public function record(string $capability, string $publicId, string $phone): array
    {
        if (!$this->enabled) {
            return ['recorded' => false, 'reason' => 'feature_disabled_noop'];
        }
        if ($capability === '' || !hash_equals($this->capability, $capability)) {
            return ['recorded' => false, 'reason' => 'untrusted_signal'];
        }

        $this->pdo->exec('BEGIN IMMEDIATE');
        try {
            $stmt = $this->pdo->prepare(
                'SELECT k.id AS key_id, k.bound_phone, k.period_id, p.status AS period_status, c.status AS claim_status
                 FROM campaign_share_keys k
                 INNER JOIN campaign_share_claims c ON c.key_id = k.id
                 INNER JOIN campaign_share_periods p ON p.id = k.period_id
                 WHERE k.public_id = ?'
            );
            $stmt->execute([$publicId]);
            $row = $stmt->fetch();
            if (!$row) {
                $this->pdo->exec('COMMIT');

                return ['recorded' => false, 'reason' => 'not_campaign_key'];
            }
            if (!hash_equals((string) $row['bound_phone'], $phone)) {
                $this->pdo->exec('COMMIT');

                return ['recorded' => false, 'reason' => 'phone_mismatch'];
            }
            if ((string) $row['period_status'] !== 'open') {
                $this->pdo->exec('COMMIT');

                return ['recorded' => false, 'reason' => 'period_closed_usage_not_recorded'];
            }
            if ((string) $row['claim_status'] === 'used') {
                $this->pdo->exec('COMMIT');

                return ['recorded' => true, 'reason' => 'already_recorded'];
            }
            $now = (int) ($this->clock)();
            $this->pdo->prepare(
                'UPDATE campaign_share_claims SET status = ?, used_at = ? WHERE key_id = ? AND status = ?'
            )->execute(['used', $now, (int) $row['key_id'], 'claimed_bound']);
            $this->pdo->exec('COMMIT');

            return ['recorded' => true, 'reason' => 'recorded'];
        } catch (\Throwable $e) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }
            throw $e;
        }
    }
}
