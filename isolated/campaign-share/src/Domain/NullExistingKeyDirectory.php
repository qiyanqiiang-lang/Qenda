<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Domain;

final class NullExistingKeyDirectory implements ExistingKeyDirectory
{
    public function findByPhone(string $phone): ?LegacyBinding
    {
        return null;
    }
}
