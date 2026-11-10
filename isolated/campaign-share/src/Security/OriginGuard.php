<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Security;

use Qenda\CampaignShare\Http\Request;

final class OriginGuard
{
    public function __construct(private readonly string $allowedOrigin)
    {
    }

    public function allows(Request $request): bool
    {
        $origin = trim((string) $request->header('origin'));
        if ($origin !== '') {
            return hash_equals($this->allowedOrigin, rtrim($origin, '/'));
        }
        $referer = trim((string) $request->header('referer'));
        if ($referer === '') {
            return false;
        }
        $parts = parse_url($referer);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return false;
        }
        $port = isset($parts['port']) ? ':' . $parts['port'] : '';
        $rebuilt = $parts['scheme'] . '://' . $parts['host'] . $port;

        return hash_equals($this->allowedOrigin, $rebuilt);
    }
}
