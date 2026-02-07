<?php

namespace App\Router;

use App\Support\Request;
use App\Support\Response;

class Router
{
    private Request $request;
    private array $routes = [];

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    public function get(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('GET', $path, $handler, $middleware);
    }

    public function post(string $path, $handler, array $middleware = []): void
    {
        $this->addRoute('POST', $path, $handler, $middleware);
    }

    private function addRoute(string $method, string $path, $handler, array $middleware): void
    {
        $this->routes[] = compact('method', 'path', 'handler', 'middleware');
    }

    public function dispatch(): Response
    {
        foreach ($this->routes as $route) {
            if (
                $route['method'] === $this->request->getMethod()
                && $route['path'] === $this->request->getPath()
            ) {
                return $this->runMiddleware($route['middleware'], $route['handler']);
            }
        }

        return Response::error('Not Found', 404);
    }

    private function runMiddleware(array $middleware, $handler): Response
    {
        $next = function (Request $request) use ($handler) {
            if (is_array($handler)) {
                [$class, $method] = $handler;
                $controller = new $class();
                return $controller->$method($request);
            }
        
            return call_user_func($handler, $request);
        };

        // Reverse so first middleware runs first
        foreach (array_reverse($middleware) as $middlewareClass) {
            $next = function (Request $request) use ($middlewareClass, $next) {
                $middleware = new $middlewareClass();
                return $middleware->handle($request, $next);
            };
        }

        return $next($this->request);
    }
}
