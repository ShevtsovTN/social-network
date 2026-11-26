<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Authenticate\AuthenticateHandler;
use SocialNetwork\Core\Application\Authenticate\AuthenticateQuery;
use SocialNetwork\Core\Application\Authenticate\Unauthenticated;
use SocialNetwork\Core\Tests\Unit\Fake\FakeTokenService;
use SocialNetwork\CoreContract\FeedFixture;

final class AuthenticateHandlerTest extends TestCase
{
    private FakeTokenService $tokens;
    private AuthenticateHandler $handler;

    protected function setUp(): void
    {
        $this->tokens = new FakeTokenService('test-secret');
        $this->handler = new AuthenticateHandler($this->tokens);
    }

    public function testReturnsTheOwnerOfAValidToken(): void
    {
        $token = $this->tokens->issue(FeedFixture::userId(7));

        $userId = $this->handler->handle(new AuthenticateQuery($token));

        self::assertTrue($userId->equals(FeedFixture::userId(7)));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function badTokens(): iterable
    {
        yield 'no token' => [''];
        yield 'garbage' => ['not-a-token'];
        yield 'bearer prefix left in' => ['Bearer v1.a.b'];
        yield 'forged signature' => ['v1.' . 'a0000000-0000-4000-8000-000000000007' . '.deadbeef'];
        yield 'token signed with another secret' => [(new FakeTokenService('other-secret'))->issue(FeedFixture::userId(7))];
    }

    #[DataProvider('badTokens')]
    public function testRejectsAnyInvalidToken(string $token): void
    {
        $this->expectException(Unauthenticated::class);

        $this->handler->handle(new AuthenticateQuery($token));
    }
}
