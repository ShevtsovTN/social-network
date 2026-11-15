<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use PDO;
use SocialNetwork\Core\Application\Port\FeedSource;
use SocialNetwork\Core\Application\Port\FriendshipRepository;
use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\CoreContract\FeedStorage;
use SocialNetwork\SymfonyApp\Infrastructure\Persistence\PdoConnectionFactory;
use SocialNetwork\SymfonyApp\Infrastructure\Persistence\PdoFeedSource;
use SocialNetwork\SymfonyApp\Infrastructure\Persistence\PdoFriendshipRepository;
use SocialNetwork\SymfonyApp\Infrastructure\Persistence\PdoPostRepository;
use SocialNetwork\SymfonyApp\Infrastructure\Persistence\PdoUserRepository;

/**
 * Реальный PostgreSQL для contract-тестов репозиториев ленты: одно подключение и одна транзакция,
 * которую тест откатывает (rollBack), поэтому данные в БД не остаются.
 */
final readonly class PdoFeedStorage implements FeedStorage
{
    private function __construct(private PDO $connection)
    {
    }

    public static function begin(): self
    {
        $connection = PdoConnectionFactory::create(
            (string) getenv('DB_HOST'),
            (string) getenv('DB_PORT'),
            (string) getenv('DB_NAME'),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASSWORD'),
        );
        $connection->beginTransaction();

        return new self($connection);
    }

    public function rollBack(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    public function users(): UserRepository
    {
        return new PdoUserRepository($this->connection);
    }

    public function posts(): PostRepository
    {
        return new PdoPostRepository($this->connection);
    }

    public function friendships(): FriendshipRepository
    {
        return new PdoFriendshipRepository($this->connection);
    }

    public function feedSource(): FeedSource
    {
        return new PdoFeedSource($this->connection);
    }
}
