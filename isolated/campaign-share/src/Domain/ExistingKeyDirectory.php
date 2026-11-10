<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Domain;

interface ExistingKeyDirectory
{
    public function findByPhone(string $phone): ?LegacyBinding;
}
