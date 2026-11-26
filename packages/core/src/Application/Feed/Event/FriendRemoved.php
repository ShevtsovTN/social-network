<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Feed\Event;

use SocialNetwork\Core\Domain\User\UserId;

/**
 * $userId убрал $friendId из друзей: лента $userId сбрасывается и пересобирается при следующем чтении.
 */
final readonly class FriendRemoved implements FeedEvent
{
    public function __construct(public UserId $userId, public UserId $friendId)
    {
    }
}
