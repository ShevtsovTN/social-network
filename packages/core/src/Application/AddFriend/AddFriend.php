<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\AddFriend;

use SocialNetwork\Core\Domain\Friend\InvalidFriendship;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserNotFound;

interface AddFriend
{
    /**
     * Идемпотентно: повторное добавление того же друга не ошибка.
     *
     * @throws InvalidUserData если friendId не UUID (field = friendId)
     * @throws InvalidFriendship если friendId совпадает с id текущего пользователя (field = friendId)
     * @throws UserNotFound если пользователя friendId нет
     */
    public function handle(AddFriendCommand $command): void;
}
