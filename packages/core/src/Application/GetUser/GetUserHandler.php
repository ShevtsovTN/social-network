<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetUser;

use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\User\User;
use SocialNetwork\Core\Domain\User\UserId;
use SocialNetwork\Core\Domain\User\UserNotFound;

final readonly class GetUserHandler implements GetUser
{
    public function __construct(private UserRepository $users)
    {
    }

    public function handle(GetUserQuery $query): User
    {
        $id = UserId::fromString($query->userId);

        return $this->users->findById($id) ?? throw UserNotFound::withId($id);
    }
}
