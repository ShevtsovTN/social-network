<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Login;

final readonly class LoginCommand
{
    public function __construct(
        public string $userId,
        #[\SensitiveParameter]
        public string $password,
    ) {
    }
}
