<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Security;

use PDO;

final class CsrfStore
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly \Closure $clock,
        private readonly int $ttl,
    ) {
    }

    /** @return array{cookie: string, token: string} */
    public function issue(): array
    {
        $cookie = bin2hex(random_bytes(16));
        $token = bin2hex(random_bytes(32));
        $now = (int) ($this->clock)();
        $this->pdo->prepare('INSERT INTO campaign_share_csrf (cookie_hash, token_hash, expires_at) VALUES (?, ?, ?)')
            ->execute([hash('sha256', $cookie), hash('sha256', $token), $now + $this->ttl]);

        return ['cookie' => $cookie, 'token' => $token];
    }

    public function assert(?string $cookie, ?string $token): bool
    {
        if ($cookie === null || $cookie === '' || $token === null || $token === '') {
            return false;
        }
        $now = (int) ($this->clock)();
        $stmt = $this->pdo->prepare('SELECT token_hash FROM campaign_share_csrf WHERE cookie_hash = ? AND expires_at > ?');
        $stmt->execute([hash('sha256', $cookie), $now]);
        $row = $stmt->fetch();
        if (!$row) {
            return false;
        }

        return hash_equals((string) $row['token_hash'], hash('sha256', $token));
    }
}
