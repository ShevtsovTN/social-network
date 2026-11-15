<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\AddFriend;

use SocialNetwork\Core\Application\Feed\Event\FriendAdded;
use SocialNetwork\Core\Application\Port\FeedEventPublisher;
use SocialNetwork\Core\Application\Port\FriendshipRepository;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\Friend\Friendship;
use SocialNetwork\Core\Domain\User\UserId;
use SocialNetwork\Core\Domain\User\UserNotFound;

final readonly class AddFriendHandler implements AddFriend
{
    public function __construct(
        private UserRepository $users,
        private FriendshipRepository $friendships,
        private FeedEventPublisher $events,
    ) {
    }

    public function handle(AddFriendCommand $command): void
    {
        $friendId = UserId::fromString($command->friendId, 'friendId');
        $friendship = Friendship::between($command->actorId, $friendId);

        if ($this->users->findById($friendId) === null) {
            throw UserNotFound::withId($friendId);
        }

        $this->friendships->add($friendship);
        $this->events->publish(new FriendAdded($command->actorId, $friendId));
    }
}
