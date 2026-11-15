<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\AddFriend;

use SocialNetwork\Core\Domain\User\UserId;

final readonly class AddFriendCommand
{
    public function __construct(public UserId $actorId, public string $friendId)
    {
    }
}
