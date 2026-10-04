<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\CoreContract\UserRepositoryContractTest;

/**
 * Фейк тоже обязан соблюдать контракт порта, иначе unit-тесты handlers проверяют не то.
 */
final class InMemoryUserRepositoryTest extends UserRepositoryContractTest
{
    protected function createRepository(): UserRepository
    {
        return new InMemoryUserRepository();
    }
}
