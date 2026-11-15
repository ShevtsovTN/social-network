<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Persistence;

use DateTimeImmutable;
use PDO;
use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\Post\PostText;
use SocialNetwork\Core\Domain\User\UserId;

final readonly class PdoPostRepository implements PostRepository
{
    private const string TIMESTAMP_FORMAT = 'Y-m-d H:i:s.uP';

    public function __construct(private PDO $connection)
    {
    }

    public function add(Post $post): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO posts (id, author_id, text, created_at)
             VALUES (:id, :author_id, :text, :created_at)',
        );

        $statement->execute([
            'id' => $post->id->value,
            'author_id' => $post->authorId->value,
            'text' => $post->text->value,
            'created_at' => $post->createdAt->format(self::TIMESTAMP_FORMAT),
        ]);
    }

    public function findById(PostId $id): ?Post
    {
        $statement = $this->connection->prepare(
            'SELECT id, author_id, text, created_at FROM posts WHERE id = :id',
        );
        $statement->execute(['id' => $id->value]);
        $row = $statement->fetch();

        return $row === false ? null : self::postFromRow($row);
    }

    public function findByIds(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        // Один параметр-массив вместо списка плейсхолдеров переменной длины: SQL остаётся статическим.
        // В литерал попадают только значения PostId, а это проверенные UUID.
        $statement = $this->connection->prepare(
            'SELECT id, author_id, text, created_at FROM posts WHERE id = ANY(CAST(:ids AS uuid[]))',
        );
        $statement->execute([
            'ids' => '{' . implode(',', array_map(static fn (PostId $id): string => $id->value, $ids)) . '}',
        ]);

        return array_map(self::postFromRow(...), $statement->fetchAll());
    }

    public function update(Post $post): void
    {
        $statement = $this->connection->prepare('UPDATE posts SET text = :text WHERE id = :id');
        $statement->execute(['id' => $post->id->value, 'text' => $post->text->value]);
    }

    public function delete(PostId $id): void
    {
        $statement = $this->connection->prepare('DELETE FROM posts WHERE id = :id');
        $statement->execute(['id' => $id->value]);
    }

    /**
     * @param array<string, string> $row
     */
    private static function postFromRow(array $row): Post
    {
        return Post::reconstitute(
            PostId::fromString($row['id']),
            UserId::fromString($row['author_id']),
            PostText::fromString($row['text']),
            new DateTimeImmutable($row['created_at']),
        );
    }
}
