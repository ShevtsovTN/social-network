<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Feed\Event;

use SocialNetwork\Core\Domain\User\UserId;

/**
 * $userId добавил $friendId в друзья: в его ленту попадают уже существующие посты друга.
 */
final readonly class FriendAdded implements FeedEvent
{
    public function __construct(public UserId $userId, public UserId $friendId)
    {
    }
}
