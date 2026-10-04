<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Login;

use SocialNetwork\Core\Application\Port\PasswordHasher;
use SocialNetwork\Core\Application\Port\TokenIssuer;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\PlainPassword;
use SocialNetwork\Core\Domain\User\UserId;

final readonly class LoginHandler implements Login
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private TokenIssuer $tokenIssuer,
    ) {
    }

    public function handle(LoginCommand $command): string
    {
        try {
            $userId = UserId::fromString($command->userId);
            $password = PlainPassword::fromInput($command->password);
        } catch (InvalidUserData) {
            throw InvalidCredentials::create();
        }

        $user = $this->users->findById($userId);

        if ($user === null) {
            // Выполняем ту же дорогую операцию, что и при проверке пароля существующего
            // пользователя, чтобы время ответа не выдавало, существует ли такой id.
            $this->passwordHasher->hash($password);

            throw InvalidCredentials::create();
        }

        if (!$this->passwordHasher->verify($password, $user->passwordHash)) {
            throw InvalidCredentials::create();
        }

        return $this->tokenIssuer->issue($user->id);
    }
}
