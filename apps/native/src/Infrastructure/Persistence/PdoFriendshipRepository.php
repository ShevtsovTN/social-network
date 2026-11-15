<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Infrastructure\Persistence;

use PDO;
use SocialNetwork\Core\Application\Port\FriendshipRepository;
use SocialNetwork\Core\Domain\Friend\Friendship;
use SocialNetwork\Core\Domain\User\UserId;

final readonly class PdoFriendshipRepository implements FriendshipRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function add(Friendship $friendship): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO friendships (user_id, friend_id) VALUES (:user_id, :friend_id)
             ON CONFLICT (user_id, friend_id) DO NOTHING',
        );
        $statement->execute(['user_id' => $friendship->userId->value, 'friend_id' => $friendship->friendId->value]);
    }

    public function remove(UserId $userId, UserId $friendId): void
    {
        $statement = $this->connection->prepare(
            'DELETE FROM friendships WHERE user_id = :user_id AND friend_id = :friend_id',
        );
        $statement->execute(['user_id' => $userId->value, 'friend_id' => $friendId->value]);
    }

    public function followerIdsOf(UserId $authorId, int $limit, ?UserId $after = null): array
    {
        // Два статических запроса вместо одного с необязательным условием: не нужно повторять именованный параметр.
        $statement = $after === null
            ? $this->connection->prepare(
                'SELECT user_id FROM friendships WHERE friend_id = :author_id ORDER BY user_id LIMIT :limit',
            )
            : $this->connection->prepare(
                'SELECT user_id FROM friendships WHERE friend_id = :author_id AND user_id > :after
                 ORDER BY user_id LIMIT :limit',
            );

        $statement->bindValue('author_id', $authorId->value);
        if ($after !== null) {
            $statement->bindValue('after', $after->value);
        }
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): UserId => UserId::fromString($row['user_id']),
            $statement->fetchAll(),
        );
    }
}
