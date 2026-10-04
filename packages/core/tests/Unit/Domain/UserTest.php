<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\User\BirthDate;
use SocialNetwork\Core\Domain\User\City;
use SocialNetwork\Core\Domain\User\Gender;
use SocialNetwork\Core\Domain\User\Interests;
use SocialNetwork\Core\Domain\User\Name;
use SocialNetwork\Core\Domain\User\PasswordHash;
use SocialNetwork\Core\Domain\User\User;
use SocialNetwork\Core\Domain\User\UserId;
use SocialNetwork\Core\Tests\Unit\Support\AssertsInvalidUserData;

final class UserTest extends TestCase
{
    use AssertsInvalidUserData;

    public function testRegistersUserWithBirthDateInThePast(): void
    {
        $user = $this->register('2000-01-01');

        self::assertSame('2000-01-01', $user->birthDate->toString());
    }

    public function testRegistersUserBornToday(): void
    {
        $user = $this->register('2026-10-03');

        self::assertSame('2026-10-03', $user->birthDate->toString());
    }

    public function testRejectsBirthDateInTheFuture(): void
    {
        $this->assertInvalidField('birthDate', fn () => $this->register('2026-10-04'));
    }

    public function testReconstitutionDoesNotRevalidateTheClock(): void
    {
        $user = User::reconstitute(
            UserId::fromString('11111111-1111-4111-8111-111111111111'),
            Name::fromString('Ivan', 'firstName'),
            Name::fromString('Petrov', 'lastName'),
            BirthDate::fromString('2999-01-01'),
            Gender::Male,
            Interests::none(),
            City::fromString('Moscow'),
            PasswordHash::fromString('hash'),
        );

        self::assertSame('2999-01-01', $user->birthDate->toString());
    }

    private function register(string $birthDate): User
    {
        return User::register(
            UserId::fromString('11111111-1111-4111-8111-111111111111'),
            Name::fromString('Ivan', 'firstName'),
            Name::fromString('Petrov', 'lastName'),
            BirthDate::fromString($birthDate),
            Gender::Male,
            Interests::none(),
            City::fromString('Moscow'),
            PasswordHash::fromString('hash'),
            new \DateTimeImmutable('2026-10-03 09:00:00', new \DateTimeZone('UTC')),
        );
    }
}
