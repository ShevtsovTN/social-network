<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\RegisterUser;

use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserId;

interface RegisterUser
{
    /**
     * @return UserId идентификатор созданного пользователя
     *
     * @throws InvalidUserData
     */
    public function handle(RegisterUserCommand $command): UserId;
}
