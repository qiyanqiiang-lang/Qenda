<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Security;

use PDO;

final class ChallengeStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly \Closure $clock,
        private readonly int $ttl,
    ) {
    }

    public function issue(string $phone, string $invite, int $promoterId): string
    {
        $id = bin2hex(random_bytes(16));
        $now = (int) ($this->clock)();
        $this->pdo->prepare(
            'INSERT INTO campaign_share_challenges (id, phone, invite_code, promoter_id, expires_at, consumed_at) VALUES (?, ?, ?, ?, ?, NULL)'
        )->execute([$id, $phone, $invite, $promoterId, $now + $this->ttl]);

        return $id;
    }

    /** @return array<string, mixed>|null */
    public function findOpen(string $id): ?array
    {
        $now = (int) ($this->clock)();
        $stmt = $this->pdo->prepare(
            'SELECT id, phone, invite_code, promoter_id, expires_at, consumed_at FROM campaign_share_challenges WHERE id = ? AND consumed_at IS NULL AND expires_at > ?'
        );
        $stmt->execute([$id, $now]);
        $row = $stmt->fetch();

        return $row ?: null;
    }

    public function consume(string $id): void
    {
        $now = (int) ($this->clock)();
        $this->pdo->prepare('UPDATE campaign_share_challenges SET consumed_at = ? WHERE id = ?')
            ->execute([$now, $id]);
    }
}
