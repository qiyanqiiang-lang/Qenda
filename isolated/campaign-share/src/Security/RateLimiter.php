<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Security;

use PDO;

final class RateLimiter
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly int $max,
        private readonly int $window,
        private readonly \Closure $clock,
    ) {
    }

    public function check(string $bucket): string
    {
        $now = (int) ($this->clock)();
        try {
            $this->pdo->exec('BEGIN IMMEDIATE');
            $select = $this->pdo->prepare('SELECT window_start, hits FROM campaign_share_rate_limits WHERE bucket = ?');
            $select->execute([$bucket]);
            $row = $select->fetch();
            if (!$row || ($now - (int) $row['window_start']) >= $this->window) {
                if ($row) {
                    $this->pdo->prepare('UPDATE campaign_share_rate_limits SET window_start = ?, hits = 1 WHERE bucket = ?')
                        ->execute([$now, $bucket]);
                } else {
                    $this->pdo->prepare('INSERT INTO campaign_share_rate_limits (bucket, window_start, hits) VALUES (?, ?, 1)')
                        ->execute([$bucket, $now]);
                }
                $this->pdo->exec('COMMIT');

                return 'ok';
            }
            if ((int) $row['hits'] >= $this->max) {
                $this->pdo->exec('COMMIT');

                return 'limited';
            }
            $this->pdo->prepare('UPDATE campaign_share_rate_limits SET hits = hits + 1 WHERE bucket = ?')
                ->execute([$bucket]);
            $this->pdo->exec('COMMIT');

            return 'ok';
        } catch (\Throwable) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (\Throwable) {
            }

            return 'error';
        }
    }
}
