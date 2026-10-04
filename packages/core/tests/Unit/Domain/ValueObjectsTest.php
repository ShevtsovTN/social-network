<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\User\City;
use SocialNetwork\Core\Domain\User\Gender;
use SocialNetwork\Core\Domain\User\Name;
use SocialNetwork\Core\Domain\User\PasswordHash;
use SocialNetwork\Core\Domain\User\UserId;
use SocialNetwork\Core\Tests\Unit\Support\AssertsInvalidUserData;

final class ValueObjectsTest extends TestCase
{
    use AssertsInvalidUserData;

    public function testNameIsTrimmedAndReportsItsField(): void
    {
        self::assertSame('Иван', Name::fromString('  Иван ', 'firstName')->value);

        $this->assertInvalidField('lastName', static fn () => Name::fromString('   ', 'lastName'));
        $this->assertInvalidField('firstName', static fn () => Name::fromString(str_repeat('я', 101), 'firstName'));
        self::assertSame(100, mb_strlen(Name::fromString(str_repeat('я', 100), 'firstName')->value));
    }

    public function testNameRejectsControlCharactersAndBrokenEncoding(): void
    {
        $this->assertInvalidField('firstName', static fn () => Name::fromString("Ivan\nPetrov", 'firstName'));
        $this->assertInvalidField('firstName', static fn () => Name::fromString("\xC3\x28", 'firstName'));
    }

    public function testCityIsRequired(): void
    {
        self::assertSame('Москва', City::fromString(' Москва ')->value);
        $this->assertInvalidField('city', static fn () => City::fromString(''));
    }

    public function testGenderIsParsedCaseInsensitively(): void
    {
        self::assertSame(Gender::Male, Gender::fromString('male'));
        self::assertSame(Gender::Female, Gender::fromString(' FEMALE '));
        self::assertSame(Gender::Other, Gender::fromString('Other'));
        $this->assertInvalidField('gender', static fn () => Gender::fromString('unknown'));
        $this->assertInvalidField('gender', static fn () => Gender::fromString(''));
    }

    public function testUserIdMustBeUuidAndIsNormalizedToLowerCase(): void
    {
        $id = UserId::fromString('AAAAAAAA-AAAA-4AAA-8AAA-AAAAAAAAAAAA');

        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $id->value);
        self::assertTrue($id->equals(UserId::fromString('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa')));

        foreach (['', '1', 'not-a-uuid', '11111111-1111-4111-8111-11111111111', "11111111-1111-4111-8111-111111111111\n", '1; DROP TABLE users'] as $invalid) {
            $this->assertInvalidField('id', static fn () => UserId::fromString($invalid));
        }
    }

    public function testPasswordHashMustBeNonEmptyAndFitTheColumn(): void
    {
        self::assertSame('x', PasswordHash::fromString('x')->value);
        $this->assertInvalidField('password', static fn () => PasswordHash::fromString(''));
        $this->assertInvalidField('password', static fn () => PasswordHash::fromString(str_repeat('a', 256)));
    }
}
