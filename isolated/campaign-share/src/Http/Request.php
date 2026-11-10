<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Http;

final class Request
{
    /**
     * @param array<string, string> $query
     * @param array<string, string> $headers
     * @param array<string, string> $cookies
     * @param array<string, string> $form
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        public readonly string $body,
        public readonly string $remoteAddr,
        public readonly array $cookies,
        public readonly array $form = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if (trim($this->body) === '') {
            return [];
        }
        try {
            $decoded = json_decode($this->body, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return is_array($decoded) ? $decoded : [];
    }

    public function header(string $name): ?string
    {
        $key = strtolower($name);

        return $this->headers[$key] ?? null;
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?: '/');
        $query = [];
        $queryString = (string) (parse_url($uri, PHP_URL_QUERY) ?: '');
        parse_str($queryString, $parsed);
        foreach ($parsed as $key => $value) {
            if (is_string($key) && (is_string($value) || is_numeric($value))) {
                $query[$key] = (string) $value;
            }
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        $cookies = [];
        foreach ($_COOKIE as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $cookies[$key] = $value;
            }
        }
        $form = [];
        foreach ($_POST as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $form[$key] = $value;
            }
        }

        return new self(
            method: $method,
            path: $path,
            query: $query,
            headers: $headers,
            body: (string) file_get_contents('php://input'),
            remoteAddr: (string) ($_SERVER['REMOTE_ADDR'] ?? ''),
            cookies: $cookies,
            form: $form,
        );
    }
}
