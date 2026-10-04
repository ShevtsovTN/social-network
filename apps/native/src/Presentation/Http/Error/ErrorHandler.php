<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Error;

use SocialNetwork\Core\Application\Login\InvalidCredentials;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserNotFound;
use SocialNetwork\NativeApp\Presentation\Http\Request\MalformedRequest;
use SocialNetwork\NativeApp\Presentation\Http\Response\JsonResponse;
use SocialNetwork\NativeApp\Presentation\Http\Routing\MethodNotAllowed;
use SocialNetwork\NativeApp\Presentation\Http\Routing\RouteNotFound;
use Throwable;

/**
 * Единственное место, где доменные/presentation-исключения превращаются в HTTP-ответ.
 */
final class ErrorHandler
{
    public function toResponse(Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof InvalidUserData => new JsonResponse(400, [
                'message' => $exception->getMessage(),
                'field' => $exception->field,
            ]),
            $exception instanceof MalformedRequest => new JsonResponse(400, [
                'message' => $exception->getMessage(),
                'field' => $exception->field,
            ]),
            $exception instanceof InvalidCredentials => new JsonResponse(401, [
                'message' => $exception->getMessage(),
            ]),
            $exception instanceof UserNotFound, $exception instanceof RouteNotFound => new JsonResponse(404, [
                'message' => $exception->getMessage(),
            ]),
            $exception instanceof MethodNotAllowed => new JsonResponse(405, [
                'message' => $exception->getMessage(),
            ]),
            default => $this->internalError($exception),
        };
    }

    private function internalError(Throwable $exception): JsonResponse
    {
        error_log(sprintf('[native] unhandled %s: %s', $exception::class, $exception->getMessage()));

        return new JsonResponse(500, ['message' => 'Internal Server Error']);
    }
}
