<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\FriendshipRepository;
use SocialNetwork\Core\Domain\Friend\Friendship;
use SocialNetwork\Core\Domain\User\UserId;

final class InMemoryFriendshipRepository implements FriendshipRepository
{
    /** @var array<string, array<string, true>> userId => [friendId => true] */
    private array $friends = [];

    public function add(Friendship $friendship): void
    {
        $this->friends[$friendship->userId->value][$friendship->friendId->value] = true;
    }

    public function remove(UserId $userId, UserId $friendId): void
    {
        unset($this->friends[$userId->value][$friendId->value]);
    }

    public function followerIdsOf(UserId $authorId, int $limit, ?UserId $after = null): array
    {
        $followers = [];

        foreach ($this->friends as $userId => $friendIds) {
            if (isset($friendIds[$authorId->value]) && ($after === null || strcmp($userId, $after->value) > 0)) {
                $followers[] = $userId;
            }
        }

        usort($followers, strcmp(...));

        return array_map(
            static fn (string $id): UserId => UserId::fromString($id),
            array_slice($followers, 0, $limit),
        );
    }

    /**
     * @return list<string> id друзей пользователя
     */
    public function friendIdsOf(UserId $userId): array
    {
        return array_keys($this->friends[$userId->value] ?? []);
    }

    public function has(UserId $userId, UserId $friendId): bool
    {
        return isset($this->friends[$userId->value][$friendId->value]);
    }
}
