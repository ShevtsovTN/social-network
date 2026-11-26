<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Error;

use SocialNetwork\Core\Application\Authenticate\Unauthenticated;
use SocialNetwork\Core\Application\Login\InvalidCredentials;
use SocialNetwork\Core\Domain\Feed\InvalidFeedPage;
use SocialNetwork\Core\Domain\Friend\InvalidFriendship;
use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\PostNotFound;
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
    /**
     * Поля домена, которые в запросе называются иначе: идентификатор друга приходит параметром пути userId.
     */
    private const array FIELD_ALIASES = ['friendId' => 'userId'];

    public function toResponse(Throwable $exception): JsonResponse
    {
        return match (true) {
            $exception instanceof InvalidUserData,
            $exception instanceof InvalidFriendship => new JsonResponse(400, [
                'message' => $exception->getMessage(),
                'field' => self::FIELD_ALIASES[$exception->field] ?? $exception->field,
            ]),
            $exception instanceof InvalidPostData,
            $exception instanceof InvalidFeedPage,
            $exception instanceof MalformedRequest => new JsonResponse(400, [
                'message' => $exception->getMessage(),
                'field' => $exception->field,
            ]),
            $exception instanceof InvalidCredentials,
            $exception instanceof Unauthenticated => new JsonResponse(401, [
                'message' => $exception->getMessage(),
            ]),
            $exception instanceof UserNotFound,
            $exception instanceof PostNotFound,
            $exception instanceof RouteNotFound => new JsonResponse(404, [
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
