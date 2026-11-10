<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Domain;

final class ClaimOutcome
{
    public function __construct(
        public readonly string $type,
        public readonly string $message,
        public readonly ?string $secret,
        public readonly ?string $phone,
        public readonly ?string $publicId,
        public readonly ?string $status,
        public readonly bool $attributionChanged,
        public readonly ?string $denialCode,
        public readonly bool $claimed,
    ) {
    }
}
