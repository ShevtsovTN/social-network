<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use SocialNetwork\CoreContract\FeedEventChannel;
use SocialNetwork\CoreContract\FeedEventQueueContractTest;

/**
 * Идёт на настоящий Redis, у каждого теста свой стрим.
 */
final class RedisFeedEventQueueTest extends FeedEventQueueContractTest
{
    use UsesIsolatedRedis;

    protected function createChannel(): FeedEventChannel
    {
        return new RedisFeedEventChannel($this->connection(), $this->keyspace());
    }
}
