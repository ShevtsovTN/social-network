<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Friend;

use SocialNetwork\Core\Domain\User\UserId;

/**
 * Односторонняя дружба: $userId добавил $friendId в друзья. Это не делает $userId другом $friendId.
 */
final readonly class Friendship
{
    private function __construct(public UserId $userId, public UserId $friendId)
    {
    }

    public static function between(UserId $userId, UserId $friendId): self
    {
        if ($userId->equals($friendId)) {
            throw InvalidFriendship::forField('friendId', 'You cannot add yourself as a friend.');
        }

        return new self($userId, $friendId);
    }
}
