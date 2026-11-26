<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\RemoveFriend;

use SocialNetwork\Core\Domain\User\UserId;

final readonly class RemoveFriendCommand
{
    public function __construct(public UserId $actorId, public string $friendId)
    {
    }
}
