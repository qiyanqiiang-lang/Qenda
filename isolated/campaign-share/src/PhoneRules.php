<?php

declare(strict_types=1);

namespace Qenda\CampaignShare;

final class PhoneRules
{
    public static function normalize(string $raw): ?string
    {
        if (preg_match('/\s/', $raw) === 1) {
            $raw = preg_replace('/\s+/', '', $raw) ?? $raw;
        }
        if (preg_match('/^1[3-9]\d{9}$/', $raw) !== 1) {
            return null;
        }

        return $raw;
    }

    public static function looksLikePhoneQuery(string $raw): bool
    {
        if (preg_match('/\d{3}\*+\d{2,}/', $raw) === 1 || str_contains(strtolower($raw), 'phone')) {
            return true;
        }
        $digits = preg_replace('/\D+/', '', $raw) ?? '';

        return preg_match('/^1[3-9]\d{9}$/', $digits) === 1;
    }
}
