<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Domain;

final class LegacyBinding
{
    public function __construct(
        public readonly string $secret,
        public readonly string $publicId,
        public readonly ?string $attribution,
        public readonly string $source,
    ) {
    }
}
