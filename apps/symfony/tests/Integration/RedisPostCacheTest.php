<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use SocialNetwork\Core\Application\Port\PostCache;
use SocialNetwork\CoreContract\PostCacheContractTest;
use SocialNetwork\SymfonyApp\Infrastructure\Redis\RedisPostCache;

/**
 * Идёт на настоящий Redis, у каждого теста свой префикс ключей.
 */
final class RedisPostCacheTest extends PostCacheContractTest
{
    use UsesIsolatedRedis;

    protected function createPostCache(): PostCache
    {
        return new RedisPostCache($this->connection(), $this->keyspace(), 3600);
    }
}
