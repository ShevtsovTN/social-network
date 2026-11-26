<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\MessageRepository;
use SocialNetwork\CoreContract\MessageRepositoryContractTest;

/**
 * Фейк тоже обязан соблюдать контракт порта, иначе unit-тесты use case'ов проверяют не то.
 */
final class InMemoryMessageRepositoryTest extends MessageRepositoryContractTest
{
    protected function createRepository(): MessageRepository
    {
        return new InMemoryMessageRepository();
    }
}
