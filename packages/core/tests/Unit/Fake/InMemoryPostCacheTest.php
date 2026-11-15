<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\PostCache;
use SocialNetwork\CoreContract\PostCacheContractTest;

final class InMemoryPostCacheTest extends PostCacheContractTest
{
    protected function createPostCache(): PostCache
    {
        return new InMemoryPostCache();
    }
}
