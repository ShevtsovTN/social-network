<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Routing;

use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;

final readonly class Router
{
    /**
     * @param list<Route> $routes
     */
    public function __construct(private array $routes)
    {
    }

    /**
     * @throws RouteNotFound
     * @throws MethodNotAllowed
     */
    public function dispatch(Request $request): JsonResponse
    {
        $pathMatched = false;

        foreach ($this->routes as $route) {
            $parameters = $route->matchPath($request->path);

            if ($parameters === null) {
                continue;
            }

            if ($route->method === $request->method) {
                return $route->controller()->handle($request, $parameters);
            }

            $pathMatched = true;
        }

        throw $pathMatched
            ? MethodNotAllowed::forPath($request->method, $request->path)
            : RouteNotFound::forPath($request->path);
    }
}
