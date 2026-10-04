<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use PDO;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\CoreContract\UserRepositoryContractTest;
use SocialNetwork\SymfonyApp\Infrastructure\Persistence\PdoConnectionFactory;
use SocialNetwork\SymfonyApp\Infrastructure\Persistence\PdoUserRepository;

/**
 * Идёт на реальный PostgreSQL. Каждый тест выполняется в транзакции,
 * которая откатывается в tearDown, поэтому данные в БД не остаются.
 */
final class PdoUserRepositoryTest extends UserRepositoryContractTest
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = PdoConnectionFactory::create(
            (string) getenv('DB_HOST'),
            (string) getenv('DB_PORT'),
            (string) getenv('DB_NAME'),
            (string) getenv('DB_USER'),
            (string) getenv('DB_PASSWORD'),
        );
        $this->connection->beginTransaction();
    }

    protected function tearDown(): void
    {
        if ($this->connection->inTransaction()) {
            $this->connection->rollBack();
        }
    }

    protected function createRepository(): UserRepository
    {
        return new PdoUserRepository($this->connection);
    }
}
