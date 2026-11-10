<?php

declare(strict_types=1);

namespace Qenda\CampaignShare;

final class InviteCode
{
    public static function isWellFormed(string $code): bool
    {
        return preg_match('/^inv_[a-f0-9]{20}$/', $code) === 1;
    }
}
