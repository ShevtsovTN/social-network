<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http;

use SocialNetwork\NativeApp\Presentation\Http\Error\ErrorHandler;
use SocialNetwork\NativeApp\Presentation\Http\Request\Request;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;
use SocialNetwork\NativeApp\Presentation\Http\Routing\Router;
use Throwable;

final readonly class Application
{
    public function __construct(
        private Router $router,
        private ErrorHandler $errorHandler,
    ) {
    }

    public function handle(Request $request): JsonResponse
    {
        try {
            return $this->router->dispatch($request);
        } catch (Throwable $exception) {
            return $this->errorHandler->toResponse($exception);
        }
    }
}
