<?php

declare(strict_types=1);

namespace App\Core;

use App\Exception\MethodNotAllowedException;
use App\Exception\NotFoundException;

/**
 * Router mínimo por método + patrón. Los parámetros {nombre} solo aceptan
 * enteros positivos, así que /reservas/abc o /reservas/0 nunca llegan al controlador.
 */
final class Router
{
    /** @var list<array{method: string, regex: string, handler: callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->add('GET', $pattern, $handler);
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->add('POST', $pattern, $handler);
    }

    public function patch(string $pattern, callable $handler): void
    {
        $this->add('PATCH', $pattern, $handler);
    }

    /**
     * @param callable(Request, array<string, string>): Response $handler
     */
    public function add(string $method, string $pattern, callable $handler): void
    {
        $regex = preg_replace('#\{([a-z_]+)\}#', '(?P<$1>[1-9][0-9]{0,9})', $pattern);
        $this->routes[] = ['method' => $method, 'regex' => '#^' . $regex . '$#', 'handler' => $handler];
    }

    public function dispatch(Request $request): Response
    {
        $allowed = [];
        // HEAD se atiende con la ruta GET (el servidor web descarta el cuerpo).
        $method = $request->method === 'HEAD' ? 'GET' : $request->method;

        foreach ($this->routes as $route) {
            if (preg_match($route['regex'], $request->path, $matches) !== 1) {
                continue;
            }
            if ($route['method'] !== $method) {
                $allowed[] = $route['method'];
                continue;
            }

            $params = array_filter($matches, 'is_string', ARRAY_FILTER_USE_KEY);

            return ($route['handler'])($request, $params);
        }

        if ($allowed !== []) {
            throw new MethodNotAllowedException(array_values(array_unique($allowed)));
        }

        throw new NotFoundException('La ruta solicitada no existe.');
    }
}
