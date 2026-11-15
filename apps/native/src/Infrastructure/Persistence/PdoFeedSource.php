<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;
use PDOStatement;
use SocialNetwork\Core\Application\Port\FeedSource;
use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

final readonly class PdoFeedSource implements FeedSource
{
    public function __construct(private PDO $connection)
    {
    }

    public function latestFromFriends(UserId $reader, int $limit): array
    {
        $statement = $this->connection->prepare(
            'SELECT p.id, p.created_at
             FROM friendships f
             JOIN posts p ON p.author_id = f.friend_id
             WHERE f.user_id = :reader_id
             ORDER BY p.created_at DESC, p.id DESC
             LIMIT :limit',
        );
        $statement->bindValue('reader_id', $reader->value);

        return $this->entries($statement, $limit);
    }

    public function latestByAuthor(UserId $author, int $limit): array
    {
        $statement = $this->connection->prepare(
            'SELECT id, created_at
             FROM posts
             WHERE author_id = :author_id
             ORDER BY created_at DESC, id DESC
             LIMIT :limit',
        );
        $statement->bindValue('author_id', $author->value);

        return $this->entries($statement, $limit);
    }

    /**
     * @return list<FeedEntry>
     */
    private function entries(PDOStatement $statement, int $limit): array
    {
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return array_map(
            static fn (array $row): FeedEntry => new FeedEntry(
                PostId::fromString($row['id']),
                new DateTimeImmutable($row['created_at']),
            ),
            $statement->fetchAll(),
        );
    }
}
