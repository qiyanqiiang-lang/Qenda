<?php

declare(strict_types=1);

namespace Qenda\CampaignShare\Http;

final class Response
{
    /**
     * @param array<string, string|list<string>> $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly array $headers,
    ) {
    }

    /** @param array<string, mixed> $data
     *  @param list<string> $cookies
     */
    public static function json(int $status, array $data, array $cookies = []): self
    {
        $headers = self::baseHeaders('application/json; charset=utf-8');
        if ($cookies !== []) {
            $headers['set-cookie'] = $cookies;
        }

        return new self($status, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), $headers);
    }

    /** @param list<string> $cookies */
    public static function html(int $status, string $html, array $cookies = [], string $csp = ''): self
    {
        $headers = self::baseHeaders('text/html; charset=utf-8');
        $headers['content-security-policy'] = $csp;
        if ($cookies !== []) {
            $headers['set-cookie'] = $cookies;
        }

        return new self($status, $html, $headers);
    }

    public static function raw(int $status, string $body, string $contentType): self
    {
        return new self($status, $body, self::baseHeaders($contentType));
    }

    /** @return array<string, string|list<string>> */
    private static function baseHeaders(string $contentType): array
    {
        return [
            'content-type' => $contentType,
            'cache-control' => 'no-store, private',
            'pragma' => 'no-cache',
            'referrer-policy' => 'no-referrer',
            'x-content-type-options' => 'nosniff',
            'x-robots-tag' => 'noindex, nofollow',
        ];
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? null;
        if (is_array($value)) {
            return implode("\n", $value);
        }

        return $value;
    }

    /** @return list<string> */
    public function setCookieHeaders(): array
    {
        $value = $this->headers['set-cookie'] ?? [];

        return is_array($value) ? $value : [$value];
    }

    /** @return array<string, string> */
    public function cookies(): array
    {
        $cookies = [];
        foreach ($this->setCookieHeaders() as $header) {
            $pair = explode(';', $header, 2)[0];
            $eq = strpos($pair, '=');
            if ($eq === false) {
                continue;
            }
            $cookies[rawurldecode(substr($pair, 0, $eq))] = rawurldecode(substr($pair, $eq + 1));
        }

        return $cookies;
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    header($name . ': ' . $item, false);
                }
                continue;
            }
            header($name . ': ' . $value, true);
        }
        echo $this->body;
    }
}
