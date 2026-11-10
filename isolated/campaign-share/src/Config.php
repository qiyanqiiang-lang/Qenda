<?php

declare(strict_types=1);

namespace Qenda\CampaignShare;

final class Config
{
    public function __construct(
        public readonly bool $enabled,
        public readonly string $environment,
        public readonly string $verifier,
        public readonly bool $allowTestVerifier,
        public readonly string $allowedOrigin,
        public readonly string $publicBase,
        public readonly int $sessionTtl,
        public readonly int $challengeTtl,
        public readonly int $rateMax,
        public readonly int $rateWindow,
        public readonly bool $cookieSecure,
    ) {
    }

    /** @param array<string, mixed> $values */
    public static function fromArray(array $values): self
    {
        return new self(
            enabled: (bool) ($values['enabled'] ?? false),
            environment: (string) ($values['environment'] ?? 'production'),
            verifier: (string) ($values['verifier'] ?? 'fail_closed'),
            allowTestVerifier: (bool) ($values['allow_test_verifier'] ?? false),
            allowedOrigin: rtrim((string) ($values['allowed_origin'] ?? 'https://qenda.cn'), '/'),
            publicBase: rtrim((string) ($values['public_base'] ?? 'https://qenda.cn'), '/'),
            sessionTtl: (int) ($values['session_ttl'] ?? 1800),
            challengeTtl: (int) ($values['challenge_ttl'] ?? 600),
            rateMax: (int) ($values['rate_max'] ?? 5),
            rateWindow: (int) ($values['rate_window'] ?? 600),
            cookieSecure: (bool) ($values['cookie_secure'] ?? true),
        );
    }

    public static function load(string $root): self
    {
        /** @var array<string, mixed> $default */
        $default = require rtrim($root, '/') . '/config/default.php';
        $enabled = (bool) ($default['enabled'] ?? false);
        if (is_file(rtrim($root, '/') . '/config/force-off.flag')) {
            $enabled = false;
        }

        return self::fromArray(array_merge($default, ['enabled' => $enabled]));
    }

    public function withEnabled(bool $enabled): self
    {
        return new self(
            enabled: $enabled,
            environment: $this->environment,
            verifier: $this->verifier,
            allowTestVerifier: $this->allowTestVerifier,
            allowedOrigin: $this->allowedOrigin,
            publicBase: $this->publicBase,
            sessionTtl: $this->sessionTtl,
            challengeTtl: $this->challengeTtl,
            rateMax: $this->rateMax,
            rateWindow: $this->rateWindow,
            cookieSecure: $this->cookieSecure,
        );
    }
}
