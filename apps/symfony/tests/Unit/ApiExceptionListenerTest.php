<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Unit;

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
use SocialNetwork\SymfonyApp\Presentation\Http\Error\ApiExceptionListener;
use SocialNetwork\SymfonyApp\Presentation\Http\Request\MalformedRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\HttpKernelInterface;

final class ApiExceptionListenerTest extends TestCase
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
        yield 'route not found' => [new NotFoundHttpException(), 404, null];
        yield 'method not allowed' => [new MethodNotAllowedHttpException(['GET']), 405, null];
    }

    #[DataProvider('errors')]
    public function testMapsExceptionsToStatusAndField(\Throwable $exception, int $status, ?string $field): void
    {
        $response = $this->respondTo($exception);

        self::assertSame($status, $response->getStatusCode());
        $body = json_decode((string) $response->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($body);
        self::assertArrayHasKey('message', $body);
        self::assertSame($field, $body['field'] ?? null);
    }

    public function testUnexpectedErrorsAreHiddenBehindA500(): void
    {
        $previous = ini_set('error_log', '/dev/null');

        try {
            $response = $this->respondTo(new \RuntimeException('secret details: password=hunter2'));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
        }

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('{"message":"Internal Server Error"}', $response->getContent());
    }

    private function respondTo(\Throwable $exception): Response
    {
        $kernel = new class implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };
        $event = new ExceptionEvent($kernel, Request::create('/post/feed'), HttpKernelInterface::MAIN_REQUEST, $exception);

        (new ApiExceptionListener())($event);

        $response = $event->getResponse();
        self::assertNotNull($response);

        return $response;
    }
}
