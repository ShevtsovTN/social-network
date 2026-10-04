<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\GetUser\GetUserHandler;
use SocialNetwork\Core\Application\GetUser\GetUserQuery;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserNotFound;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryUserRepository;
use SocialNetwork\CoreContract\UserFixture;

final class GetUserHandlerTest extends TestCase
{
    private InMemoryUserRepository $users;
    private GetUserHandler $handler;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->handler = new GetUserHandler($this->users);
    }

    public function testReturnsExistingUser(): void
    {
        $user = UserFixture::create();
        $this->users->save($user);

        $found = $this->handler->handle(new GetUserQuery(UserFixture::DEFAULT_ID));

        self::assertEquals($user, $found);
    }

    public function testIdLookupIsCaseInsensitive(): void
    {
        $this->users->save(UserFixture::create());

        $found = $this->handler->handle(new GetUserQuery(strtoupper(UserFixture::DEFAULT_ID)));

        self::assertSame(UserFixture::DEFAULT_ID, $found->id->value);
    }

    public function testThrowsWhenUserDoesNotExist(): void
    {
        $this->expectException(UserNotFound::class);

        $this->handler->handle(new GetUserQuery(UserFixture::OTHER_ID));
    }

    public function testThrowsInvalidDataWhenIdIsNotUuid(): void
    {
        $this->expectException(InvalidUserData::class);

        $this->handler->handle(new GetUserQuery('1 OR 1=1'));
    }
}
