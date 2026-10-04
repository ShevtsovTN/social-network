<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Login\InvalidCredentials;
use SocialNetwork\Core\Application\Login\LoginCommand;
use SocialNetwork\Core\Application\Login\LoginHandler;
use SocialNetwork\Core\Application\RegisterUser\RegisterUserCommand;
use SocialNetwork\Core\Application\RegisterUser\RegisterUserHandler;
use SocialNetwork\Core\Domain\User\UserId;
use SocialNetwork\Core\Tests\Unit\Fake\FakePasswordHasher;
use SocialNetwork\Core\Tests\Unit\Fake\FakeTokenService;
use SocialNetwork\Core\Tests\Unit\Fake\FixedClock;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryUserRepository;
use SocialNetwork\Core\Tests\Unit\Fake\SequentialUserIdGenerator;

final class LoginHandlerTest extends TestCase
{
    private FakePasswordHasher $hasher;
    private FakeTokenService $tokens;
    private LoginHandler $handler;
    private UserId $registeredId;

    protected function setUp(): void
    {
        $users = new InMemoryUserRepository();
        $this->hasher = new FakePasswordHasher();
        $this->tokens = new FakeTokenService('test-secret');
        $this->handler = new LoginHandler($users, $this->hasher, $this->tokens);

        $this->registeredId = (new RegisterUserHandler(
            $users,
            $this->hasher,
            new SequentialUserIdGenerator(),
            new FixedClock(new \DateTimeImmutable('2026-10-03 09:00:00', new \DateTimeZone('UTC'))),
        ))->handle(new RegisterUserCommand('Ivan', 'Petrov', '1990-05-17', 'male', [], 'Moscow', 's3cret-password'));
    }

    public function testReturnsTokenThatBelongsToTheUser(): void
    {
        $token = $this->handler->handle(new LoginCommand($this->registeredId->value, 's3cret-password'));

        $owner = $this->tokens->verify($token);
        self::assertNotNull($owner);
        self::assertTrue($owner->equals($this->registeredId));
    }

    public function testRejectsWrongPassword(): void
    {
        $this->expectException(InvalidCredentials::class);

        $this->handler->handle(new LoginCommand($this->registeredId->value, 'wrong-password'));
    }

    public function testRejectsUnknownUser(): void
    {
        $this->expectException(InvalidCredentials::class);

        $this->handler->handle(new LoginCommand('99999999-9999-4999-8999-999999999999', 's3cret-password'));
    }

    public function testUnknownUserCostsTheSameHashingWorkAsKnownUser(): void
    {
        $before = $this->hasher->hashCalls;

        try {
            $this->handler->handle(new LoginCommand('99999999-9999-4999-8999-999999999999', 's3cret-password'));
        } catch (InvalidCredentials) {
        }

        self::assertSame($before + 1, $this->hasher->hashCalls);
    }

    public function testMalformedInputIsReportedAsInvalidCredentials(): void
    {
        foreach ([['not-a-uuid', 's3cret-password'], ['', ''], [$this->registeredId->value, '']] as [$id, $password]) {
            try {
                $this->handler->handle(new LoginCommand($id, $password));
                self::fail('Login with malformed input must fail.');
            } catch (InvalidCredentials) {
                self::assertTrue(true);
            }
        }
    }

    public function testAllFailureReasonsLookTheSame(): void
    {
        $messages = [];

        foreach ([
            [$this->registeredId->value, 'wrong-password'],
            ['99999999-9999-4999-8999-999999999999', 's3cret-password'],
            ['not-a-uuid', 's3cret-password'],
        ] as [$id, $password]) {
            try {
                $this->handler->handle(new LoginCommand($id, $password));
            } catch (InvalidCredentials $exception) {
                $messages[] = $exception->getMessage();
            }
        }

        self::assertCount(3, $messages);
        self::assertSame(1, count(array_unique($messages)));
    }
}
