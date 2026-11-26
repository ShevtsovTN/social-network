<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\CoreContract\FeedEventChannel;
use SocialNetwork\CoreContract\FeedEventQueueContractTest;

final class InMemoryFeedEventChannelTest extends FeedEventQueueContractTest
{
    protected function createChannel(): FeedEventChannel
    {
        return new InMemoryFeedEventChannel();
    }
}
