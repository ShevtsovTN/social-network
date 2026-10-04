<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\RegisterUser;

final readonly class RegisterUserCommand
{
    /**
     * @param list<string> $interests
     */
    public function __construct(
        public string $firstName,
        public string $lastName,
        public string $birthDate,
        public string $gender,
        public array $interests,
        public string $city,
        #[\SensitiveParameter]
        public string $password,
    ) {
    }
}
