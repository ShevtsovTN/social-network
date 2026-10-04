<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\RegisterUser\RegisterUserCommand;
use SocialNetwork\Core\Application\RegisterUser\RegisterUserHandler;
use SocialNetwork\Core\Domain\User\Gender;
use SocialNetwork\Core\Domain\User\PlainPassword;
use SocialNetwork\Core\Domain\User\UserId;
use SocialNetwork\Core\Tests\Unit\Fake\FakePasswordHasher;
use SocialNetwork\Core\Tests\Unit\Fake\FixedClock;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryUserRepository;
use SocialNetwork\Core\Tests\Unit\Fake\SequentialUserIdGenerator;
use SocialNetwork\Core\Tests\Unit\Support\AssertsInvalidUserData;

final class RegisterUserHandlerTest extends TestCase
{
    use AssertsInvalidUserData;

    private InMemoryUserRepository $users;
    private FakePasswordHasher $hasher;
    private RegisterUserHandler $handler;

    protected function setUp(): void
    {
        $this->users = new InMemoryUserRepository();
        $this->hasher = new FakePasswordHasher();
        $this->handler = new RegisterUserHandler(
            $this->users,
            $this->hasher,
            new SequentialUserIdGenerator(),
            new FixedClock(new \DateTimeImmutable('2026-10-03 09:00:00', new \DateTimeZone('UTC'))),
        );
    }

    public function testRegistersUserAndReturnsGeneratedId(): void
    {
        $id = $this->handler->handle($this->command());

        self::assertSame('00000000-0000-4000-8000-000000000001', $id->value);

        $user = $this->users->findById($id);
        self::assertNotNull($user);
        self::assertSame('Ivan', $user->firstName->value);
        self::assertSame('Petrov', $user->lastName->value);
        self::assertSame('1990-05-17', $user->birthDate->toString());
        self::assertSame(Gender::Male, $user->gender);
        self::assertSame(['hiking', 'chess'], $user->interests->values);
        self::assertSame('Moscow', $user->city->value);
    }

    public function testStoresOnlyHashOfThePassword(): void
    {
        $id = $this->handler->handle($this->command());

        $user = $this->users->findById($id);
        self::assertNotNull($user);
        self::assertStringNotContainsString('s3cret-password', $user->passwordHash->value);
        self::assertTrue($this->hasher->verify(PlainPassword::fromInput('s3cret-password'), $user->passwordHash));
    }

    public function testEachRegistrationGetsItsOwnId(): void
    {
        $first = $this->handler->handle($this->command());
        $second = $this->handler->handle($this->command());

        self::assertFalse($first->equals($second));
        self::assertSame(2, $this->users->count());
    }

    public function testRejectsInvalidDataWithoutSavingAnything(): void
    {
        $cases = [
            'firstName' => ['firstName' => ''],
            'lastName' => ['lastName' => '   '],
            'birthDate' => ['birthDate' => '1990-02-30'],
            'gender' => ['gender' => 'robot'],
            'interests' => ['interests' => [str_repeat('x', 51)]],
            'city' => ['city' => ''],
            'password' => ['password' => 'short'],
        ];

        foreach ($cases as $field => $override) {
            $this->assertInvalidField($field, fn () => $this->handler->handle($this->command($override)));
        }

        self::assertSame(0, $this->users->count());
    }

    public function testRejectsBirthDateInTheFuture(): void
    {
        $this->assertInvalidField('birthDate', fn () => $this->handler->handle($this->command(['birthDate' => '2026-10-04'])));
        self::assertSame(0, $this->users->count());
    }

    public function testDoesNotHashPasswordWhenOtherDataIsInvalid(): void
    {
        $this->assertInvalidField('city', fn () => $this->handler->handle($this->command(['city' => ''])));

        self::assertSame(0, $this->hasher->hashCalls);
    }

    /**
     * @param array<string, mixed> $override
     */
    private function command(array $override = []): RegisterUserCommand
    {
        return new RegisterUserCommand(...array_merge([
            'firstName' => 'Ivan',
            'lastName' => 'Petrov',
            'birthDate' => '1990-05-17',
            'gender' => 'male',
            'interests' => ['hiking', 'chess'],
            'city' => 'Moscow',
            'password' => 's3cret-password',
        ], $override));
    }
}
