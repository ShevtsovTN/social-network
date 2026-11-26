<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\FeedCache;
use SocialNetwork\CoreContract\FeedCacheContractTest;

final class InMemoryFeedCacheTest extends FeedCacheContractTest
{
    protected function createFeedCache(): FeedCache
    {
        return new InMemoryFeedCache();
    }
}
