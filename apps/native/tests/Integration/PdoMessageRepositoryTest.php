<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Integration;

use PDO;
use SocialNetwork\Core\Application\Port\MessageRepository;
use SocialNetwork\CoreContract\MessageRepositoryContractTest;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoConnectionFactory;
use SocialNetwork\NativeApp\Infrastructure\Persistence\PdoMessageRepository;

/**
 * Идёт на реальный кластер Citus (координатор DIALOGS_DB_HOST). Каждый тест выполняется в транзакции, которая
 * откатывается в tearDown, поэтому данные не остаются. Участники из MessageFixture синтетические,
 * поэтому в их диалогах нет чужих сообщений.
 */
final class PdoMessageRepositoryTest extends MessageRepositoryContractTest
{
    private PDO $connection;

    protected function setUp(): void
    {
        $this->connection = PdoConnectionFactory::create(
            (string) getenv('DIALOGS_DB_HOST'),
            (string) getenv('DIALOGS_DB_PORT'),
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

    protected function createRepository(): MessageRepository
    {
        return new PdoMessageRepository($this->connection);
    }
}
