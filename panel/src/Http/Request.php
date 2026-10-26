<?php
declare(strict_types=1);

namespace Panel\Http;

final class Request
{
    /** @var array<string,string> parametri del percorso, es. {site} */
    public array $params = [];

    private ?array $json = null;

    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $headers,
        private readonly string $body,
        public readonly array $files = [],
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }
        $path = rawurldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
        return new self(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), rtrim($path, '/') ?: '/', $_GET, $headers,
            (string) file_get_contents('php://input'), $_FILES);
    }

    /** Stessa richiesta con un altro path (es. senza il prefisso /api/v1). */
    public function withPath(string $path): self
    {
        return new self($this->method, $path, $this->query, $this->headers, $this->body, $this->files);
    }

    public function rawBody(): string
    {
        return $this->body;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** Body JSON (o form-urlencoded) come array. */
    public function input(): array
    {
        if ($this->json !== null) {
            return $this->json;
        }
        if ($this->body === '') {
            return $this->json = $_POST;
        }
        if (str_contains($this->header('content-type') ?? '', 'json')) {
            $data = json_decode($this->body, true);
            if (!is_array($data)) {
                throw new ApiError(400, 'invalid_json', 'Il body non è JSON valido');
            }
            return $this->json = $data;
        }
        parse_str($this->body, $data);
        return $this->json = $data;
    }

    public function param(string $name): string
    {
        return $this->params[$name];
    }

    public function int(string $name, int $default, int $min = 1, int $max = PHP_INT_MAX): int
    {
        $v = filter_var($this->query[$name] ?? $default, FILTER_VALIDATE_INT);
        return max($min, min($max, $v === false ? $default : $v));
    }
}
