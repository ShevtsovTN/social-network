<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\CoreContract\FeedStorage;
use SocialNetwork\CoreContract\PostRepositoryContractTest;

/**
 * Фейк тоже обязан соблюдать контракт порта, иначе unit-тесты use case'ов проверяют не то.
 */
final class InMemoryPostRepositoryTest extends PostRepositoryContractTest
{
    protected function createStorage(): FeedStorage
    {
        return new InMemoryFeedStorage();
    }
}
