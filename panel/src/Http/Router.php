<?php
declare(strict_types=1);

namespace Panel\Http;

final class Router
{
    /** @var array{string, string, callable}[] */
    private array $routes = [];

    public function add(string $method, string $pattern, callable $handler): void
    {
        // "/sites/{site}/posts/{id}" → regex con gruppi nominati
        $re = '~^' . preg_replace('~\\\{(\w+)\\\}~', '(?<$1>[^/]+)', preg_quote($pattern, '~')) . '$~';
        $this->routes[] = [$method, $re, $handler];
    }

    public function dispatch(Request $req): Response
    {
        $allowed = [];
        foreach ($this->routes as [$method, $re, $handler]) {
            if (!preg_match($re, $req->path, $m)) {
                continue;
            }
            if ($method !== $req->method) {
                $allowed[] = $method;
                continue;
            }
            $req->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($req);
        }
        if ($allowed) {
            throw new ApiError(405, 'method_not_allowed', 'Metodo non consentito. Ammessi: ' . implode(', ', array_unique($allowed)));
        }
        throw new ApiError(404, 'not_found', 'Endpoint non trovato');
    }
}
