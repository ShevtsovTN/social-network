<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\RemoveFriend;

use SocialNetwork\Core\Application\Feed\Event\FriendRemoved;
use SocialNetwork\Core\Application\Port\FeedEventPublisher;
use SocialNetwork\Core\Application\Port\FriendshipRepository;
use SocialNetwork\Core\Domain\User\UserId;

final readonly class RemoveFriendHandler implements RemoveFriend
{
    public function __construct(
        private FriendshipRepository $friendships,
        private FeedEventPublisher $events,
    ) {
    }

    public function handle(RemoveFriendCommand $command): void
    {
        $friendId = UserId::fromString($command->friendId, 'friendId');

        $this->friendships->remove($command->actorId, $friendId);
        $this->events->publish(new FriendRemoved($command->actorId, $friendId));
    }
}
