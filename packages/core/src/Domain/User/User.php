<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

final readonly class User
{
    private function __construct(
        public UserId $id,
        public Name $firstName,
        public Name $lastName,
        public BirthDate $birthDate,
        public Gender $gender,
        public Interests $interests,
        public City $city,
        public PasswordHash $passwordHash,
    ) {
    }

    /**
     * Создание нового пользователя: применяет правила, зависящие от текущего момента.
     */
    public static function register(
        UserId $id,
        Name $firstName,
        Name $lastName,
        BirthDate $birthDate,
        Gender $gender,
        Interests $interests,
        City $city,
        PasswordHash $passwordHash,
        \DateTimeImmutable $now,
    ): self {
        if ($birthDate->isAfter($now)) {
            throw InvalidUserData::forField('birthDate', 'birthDate must not be in the future.');
        }

        return new self($id, $firstName, $lastName, $birthDate, $gender, $interests, $city, $passwordHash);
    }

    /**
     * Восстановление уже существующего пользователя из хранилища. Используется репозиториями.
     */
    public static function reconstitute(
        UserId $id,
        Name $firstName,
        Name $lastName,
        BirthDate $birthDate,
        Gender $gender,
        Interests $interests,
        City $city,
        PasswordHash $passwordHash,
    ): self {
        return new self($id, $firstName, $lastName, $birthDate, $gender, $interests, $city, $passwordHash);
    }
}
