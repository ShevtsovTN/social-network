<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\RegisterUser;

use SocialNetwork\Core\Application\Port\Clock;
use SocialNetwork\Core\Application\Port\PasswordHasher;
use SocialNetwork\Core\Application\Port\UserIdGenerator;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\User\BirthDate;
use SocialNetwork\Core\Domain\User\City;
use SocialNetwork\Core\Domain\User\Gender;
use SocialNetwork\Core\Domain\User\Interests;
use SocialNetwork\Core\Domain\User\Name;
use SocialNetwork\Core\Domain\User\PlainPassword;
use SocialNetwork\Core\Domain\User\User;
use SocialNetwork\Core\Domain\User\UserId;

final readonly class RegisterUserHandler implements RegisterUser
{
    public function __construct(
        private UserRepository $users,
        private PasswordHasher $passwordHasher,
        private UserIdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function handle(RegisterUserCommand $command): UserId
    {
        // Сначала вся дешёвая валидация, дорогое хеширование пароля в самом конце.
        $user = User::register(
            $this->idGenerator->generate(),
            Name::fromString($command->firstName, 'firstName'),
            Name::fromString($command->lastName, 'lastName'),
            BirthDate::fromString($command->birthDate),
            Gender::fromString($command->gender),
            Interests::fromList($command->interests),
            City::fromString($command->city),
            $this->passwordHasher->hash(PlainPassword::createNew($command->password)),
            $this->clock->now(),
        );

        $this->users->save($user);

        return $user->id;
    }
}
