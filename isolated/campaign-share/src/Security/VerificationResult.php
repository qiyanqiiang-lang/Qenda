<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Security;

final class VerificationResult
{
    private function __construct(
        public readonly bool $ok,
        public readonly string $internalReason,
    ) {
    }

    public static function pass(string $reason): self
    {
        return new self(true, $reason);
    }

    public static function fail(string $reason): self
    {
        return new self(false, $reason);
    }
}
