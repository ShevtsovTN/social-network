<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Integration;

use SocialNetwork\CoreContract\FeedStorage;

/**
 * Общая часть integration-тестов репозиториев ленты: хранилище на реальной БД и откат транзакции после теста.
 */
trait UsesPdoFeedStorage
{
    private ?PdoFeedStorage $pdoStorage = null;

    protected function createStorage(): FeedStorage
    {
        return $this->pdoStorage = PdoFeedStorage::begin();
    }

    protected function tearDown(): void
    {
        $this->pdoStorage?->rollBack();
        $this->pdoStorage = null;
    }
}
