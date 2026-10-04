<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use SocialNetwork\Core\Domain\User\BirthDate;
use SocialNetwork\Core\Domain\User\City;
use SocialNetwork\Core\Domain\User\Gender;
use SocialNetwork\Core\Domain\User\Interests;
use SocialNetwork\Core\Domain\User\Name;
use SocialNetwork\Core\Domain\User\PasswordHash;
use SocialNetwork\Core\Domain\User\User;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Готовые пользователи для contract-тестов. Доступен приложениям вместе с core.
 */
final class UserFixture
{
    public const string DEFAULT_ID = '11111111-1111-4111-8111-111111111111';
    public const string OTHER_ID = '22222222-2222-4222-8222-222222222222';

    private function __construct()
    {
    }

    public static function create(string $id = self::DEFAULT_ID, ?Interests $interests = null): User
    {
        return User::reconstitute(
            UserId::fromString($id),
            Name::fromString('Иван', 'firstName'),
            Name::fromString('Petrov', 'lastName'),
            BirthDate::fromString('1990-05-17'),
            Gender::Male,
            $interests ?? Interests::fromList(['hiking', 'chess', 'Кофе, чай; "кавычки"']),
            City::fromString('Москва'),
            PasswordHash::fromString('$argon2id$v=19$m=65536,t=4,p=1$c2FsdHNhbHQ$aGFzaGhhc2g'),
        );
    }
}
