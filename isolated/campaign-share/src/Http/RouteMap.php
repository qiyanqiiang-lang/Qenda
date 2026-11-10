<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Http;

final class RouteMap
{
    /** @return list<array{method: string, path: string, name: string}> */
    public static function all(): array
    {
        return [
            ['method' => 'GET', 'path' => '/campaign/share.html', 'name' => 'share_page'],
            ['method' => 'GET', 'path' => '/campaign/share.js', 'name' => 'share_js'],
            ['method' => 'GET', 'path' => '/campaign/share.css', 'name' => 'share_css'],
            ['method' => 'POST', 'path' => '/api/campaign-share/v1/challenge', 'name' => 'challenge'],
            ['method' => 'POST', 'path' => '/api/campaign-share/v1/verify', 'name' => 'verify'],
            ['method' => 'POST', 'path' => '/api/campaign-share/v1/claim', 'name' => 'claim'],
            ['method' => 'POST', 'path' => '/api/campaign-share/v1/go-use', 'name' => 'go_use'],
            ['method' => 'GET', 'path' => '/api/campaign-share/v1/me', 'name' => 'me'],
            ['method' => 'GET', 'path' => '/api/campaign-share/v1/promoter/claims', 'name' => 'promoter_list'],
            ['method' => 'POST', 'path' => '/api/campaign-share/v1/promoter/claims/search', 'name' => 'promoter_search'],
            ['method' => 'GET', 'path' => '/api/campaign-share/v1/promoter/export', 'name' => 'promoter_export'],
            ['method' => 'GET', 'path' => '/campaign/promoter.html', 'name' => 'promoter_page'],
            ['method' => 'POST', 'path' => '/campaign/promoter.html', 'name' => 'promoter_page_search'],
        ];
    }

    /** @return array{name: string, params: array<string, string>}|null */
    public static function match(string $method, string $path): ?array
    {
        $method = strtoupper($method);
        foreach (self::all() as $route) {
            if ($route['method'] === $method && $route['path'] === $path) {
                return ['name' => $route['name'], 'params' => []];
            }
        }
        if ($method === 'GET' && preg_match('#^/api/campaign-share/v1/promoter/claims/([A-Za-z0-9_-]+)$#', $path, $matches) === 1) {
            return ['name' => 'promoter_detail', 'params' => ['id' => $matches[1]]];
        }

        return null;
    }
}
