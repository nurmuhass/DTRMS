<?php
declare(strict_types=1);

namespace Dtrms\Core;

use LogicException;

final class Router
{
    private array $routes = [];

    public function add(string $method, string $path, callable $handler): void
    {
        $method = strtoupper($method);
        if (isset($this->routes[$path][$method])) {
            throw new LogicException('Duplicate route.');
        }

        $this->routes[$path][$method] = $handler;
    }

    public function dispatch(string $method, string $uri): string
    {
        $path = explode('?', $uri, 2)[0];
        $handlers = $this->routes[$path] ?? [];
        if ($handlers === []) {
            return HttpError::response(404, 'not_found', 'The requested page could not be found.');
        }

        $handler = $handlers[$method] ?? ($method === 'HEAD' ? ($handlers['GET'] ?? null) : null);
        if ($handler === null) {
            $allowed = array_keys($handlers);
            if (isset($handlers['GET']) && !isset($handlers['HEAD'])) {
                $allowed[] = 'HEAD';
            }
            header('Allow: ' . implode(', ', $allowed));
            return HttpError::response(405, 'method_not_allowed', 'This page does not accept that request method.');
        }

        return $handler();
    }
}
