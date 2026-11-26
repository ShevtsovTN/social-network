<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Authenticate\Unauthenticated;
use SocialNetwork\Core\Application\Login\InvalidCredentials;
use SocialNetwork\Core\Domain\Feed\InvalidFeedPage;
use SocialNetwork\Core\Domain\Friend\InvalidFriendship;
use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\Post\PostNotFound;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserId;
use SocialNetwork\Core\Domain\User\UserNotFound;
use SocialNetwork\NativeApp\Presentation\Http\Error\ErrorHandler;
use SocialNetwork\NativeApp\Presentation\Http\Request\MalformedRequest;
use SocialNetwork\NativeApp\Presentation\Http\Routing\MethodNotAllowed;
use SocialNetwork\NativeApp\Presentation\Http\Routing\RouteNotFound;

final class ErrorHandlerTest extends TestCase
{
    /**
     * @return iterable<string, array{\Throwable, int, string|null}>
     */
    public static function errors(): iterable
    {
        yield 'invalid user data' => [InvalidUserData::forField('firstName', 'bad'), 400, 'firstName'];
        yield 'friend id is reported as the path parameter userId' => [InvalidUserData::forField('friendId', 'bad'), 400, 'userId'];
        yield 'friendship with yourself' => [InvalidFriendship::forField('friendId', 'bad'), 400, 'userId'];
        yield 'invalid post data' => [InvalidPostData::forField('text', 'bad'), 400, 'text'];
        yield 'invalid post id' => [InvalidPostData::forField('id', 'bad'), 400, 'id'];
        yield 'invalid feed offset' => [InvalidFeedPage::forField('offset', 'bad'), 400, 'offset'];
        yield 'invalid feed limit' => [InvalidFeedPage::forField('limit', 'bad'), 400, 'limit'];
        yield 'malformed request' => [MalformedRequest::forField('text', 'bad'), 400, 'text'];
        yield 'invalid credentials' => [InvalidCredentials::create(), 401, null];
        yield 'unauthenticated' => [Unauthenticated::create(), 401, null];
        yield 'user not found' => [UserNotFound::withId(UserId::fromString('11111111-1111-4111-8111-111111111111')), 404, null];
        yield 'post not found' => [PostNotFound::withId(PostId::fromString('11111111-1111-4111-8111-111111111111')), 404, null];
        yield 'route not found' => [RouteNotFound::forPath('/nope'), 404, null];
        yield 'method not allowed' => [MethodNotAllowed::forPath('PATCH', '/post/feed'), 405, null];
    }

    #[DataProvider('errors')]
    public function testMapsExceptionsToStatusAndField(\Throwable $exception, int $status, ?string $field): void
    {
        $response = (new ErrorHandler())->toResponse($exception);

        self::assertSame($status, $response->status);
        self::assertIsArray($response->body);
        self::assertArrayHasKey('message', $response->body);
        self::assertSame($field, $response->body['field'] ?? null);
    }

    public function testUnexpectedErrorsAreHiddenBehindA500(): void
    {
        $previous = ini_set('error_log', '/dev/null');

        try {
            $response = (new ErrorHandler())->toResponse(new \RuntimeException('secret details: password=hunter2'));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        self::assertSame(500, $response->status);
        self::assertSame(['message' => 'Internal Server Error'], $response->body);
    }
}
