<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Security;

use PDO;

final class SessionStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly \Closure $clock,
        private readonly int $ttl,
    ) {
    }

    /** @return array{token: string} */
    public function mintVisitor(string $phone, string $invite, int $promoterId): array
    {
        return $this->mint('visitor', $phone, $invite, $promoterId);
    }

    /** @return array{token: string} */
    public function mintPromoter(int $promoterId): array
    {
        return $this->mint('promoter', null, null, $promoterId);
    }

    /** @return array<string, mixed>|null */
    public function findVisitor(string $token): ?array
    {
        $row = $this->find($token, 'visitor');
        if ($row === null || !is_string($row['phone']) || $row['phone'] === '') {
            return null;
        }

        return $row;
    }

    /** @return array<string, mixed>|null */
    public function findPromoter(string $token): ?array
    {
        $row = $this->find($token, 'promoter');
        if ($row === null || $row['promoter_id'] === null) {
            return null;
        }

        return $row;
    }

    /** @return array{token: string} */
    private function mint(string $purpose, ?string $phone, ?string $invite, ?int $promoterId): array
    {
        $token = bin2hex(random_bytes(32));
        $now = (int) ($this->clock)();
        $this->pdo->prepare(
            'INSERT INTO campaign_share_sessions (token_hash, phone, invite_code, promoter_id, purpose, expires_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            hash('sha256', $token),
            $phone,
            $invite,
            $promoterId,
            $purpose,
            $now + $this->ttl,
            $now,
        ]);

        return ['token' => $token];
    }

    /** @return array<string, mixed>|null */
    private function find(string $token, string $purpose): ?array
    {
        if ($token === '') {
            return null;
        }
        $now = (int) ($this->clock)();
        $stmt = $this->pdo->prepare(
            'SELECT token_hash, phone, invite_code, promoter_id, purpose, expires_at FROM campaign_share_sessions WHERE token_hash = ? AND purpose = ? AND expires_at > ?'
        );
        $stmt->execute([hash('sha256', $token), $purpose, $now]);
        $row = $stmt->fetch();

        return $row ?: null;
    }
}
