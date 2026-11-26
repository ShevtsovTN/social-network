<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\RemoveFriend;

use SocialNetwork\Core\Domain\User\InvalidUserData;

interface RemoveFriend
{
    /**
     * Идемпотентно: если пользователь не был другом (или не существует), ошибки нет.
     *
     * @throws InvalidUserData если friendId не UUID (field = friendId)
     */
    public function handle(RemoveFriendCommand $command): void;
}
