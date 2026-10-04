<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetUser;

use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\User;
use SocialNetwork\Core\Domain\User\UserNotFound;

interface GetUser
{
    /**
     * @throws InvalidUserData если id не UUID
     * @throws UserNotFound
     */
    public function handle(GetUserQuery $query): User;
}
