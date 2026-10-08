<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Router minimo con soporte de parametros nombrados tipo {id}.
 * Ej: $router->get('/lotes/{id}', [LoteController::class, 'ver']);
 */
final class Router
{
    /** @var array<string, list<array{pattern:string, params:list<string>, handler:callable|array}>> */
    private array $routes = [
        'GET'  => [],
        'POST' => [],
    ];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    private function add(string $method, string $path, callable|array $handler): void
    {
        $params = [];
        $regex = preg_replace_callback('#\{([a-zA-Z_][a-zA-Z0-9_]*)\}#', function ($m) use (&$params) {
            $params[] = $m[1];
            return '([^/]+)';
        }, $path);

        $this->routes[$method][] = [
            'pattern' => '#^' . $regex . '$#',
            'params'  => $params,
            'handler' => $handler,
        ];
    }

    public function dispatch(string $method, string $uri): void
    {
        // Quitar querystring y normalizar trailing slash (salvo raiz).
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rawurldecode($path);

        // Quitar el prefijo del subdirectorio donde vive el front controller
        // (p. ej. /OCR/public cuando se sirve desde htdocs), para que las rutas
        // registradas (/lotes, /) coincidan igual en la raiz o en subcarpeta.
        $base = defined('BASE_URL') ? BASE_URL : '';
        if ($base !== '' && str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }
        if ($path === '' || $path[0] !== '/') {
            $path = '/' . $path;
        }

        if ($path !== '/' && str_ends_with($path, '/')) {
            $path = rtrim($path, '/');
        }

        $method = strtoupper($method);
        $candidates = $this->routes[$method] ?? [];

        foreach ($candidates as $route) {
            if (preg_match($route['pattern'], $path, $matches)) {
                array_shift($matches);
                $args = [];
                foreach ($route['params'] as $i => $name) {
                    $args[$name] = $matches[$i] ?? null;
                }
                $this->invoke($route['handler'], $args);
                return;
            }
        }

        $this->notFound();
    }

    /**
     * @param callable|array{0:class-string,1:string} $handler
     * @param array<string,string>                    $args
     */
    private function invoke(callable|array $handler, array $args): void
    {
        if (is_array($handler)) {
            [$class, $action] = $handler;
            $controller = new $class();
            $controller->{$action}(...array_values($args));
            return;
        }
        $handler(...array_values($args));
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>404</h1><p>Ruta no encontrada.</p>';
    }
}
