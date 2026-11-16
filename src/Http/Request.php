<?php
declare(strict_types=1);

namespace Ramonak\Http;

final class Request
{
    /** @param array<string,string> $headers lower-case names */
    public function __construct(
        public readonly string $method,
        public readonly string $host,
        public readonly string $path,
        public readonly string $query,
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly bool $https = true,
        public readonly string $client = '',
    ) {}

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        return new self($method, strtolower(preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))), rawurldecode($path),
            (string) ($_SERVER['QUERY_STRING'] ?? ''), $headers, in_array($method, ['POST', 'PUT', 'PATCH'], true) ? (string) file_get_contents('php://input') : '',
            ($_SERVER['HTTPS'] ?? '') !== '' && ($_SERVER['HTTPS'] ?? '') !== 'off' || ($headers['x-forwarded-proto'] ?? '') === 'https',
            (string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }
}
