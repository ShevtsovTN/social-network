<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\ProcessNextFeedEvent;

use SocialNetwork\Core\Application\ApplyFeedEvent\ApplyFeedEvent;
use SocialNetwork\Core\Application\Port\FeedEventConsumer;

final readonly class ProcessNextFeedEventHandler implements ProcessNextFeedEvent
{
    public function __construct(
        private FeedEventConsumer $consumer,
        private ApplyFeedEvent $applyFeedEvent,
    ) {
    }

    public function handle(ProcessNextFeedEventCommand $command): bool
    {
        $received = $this->consumer->next($command->waitMilliseconds);

        if ($received === null) {
            return false;
        }

        $this->applyFeedEvent->handle($received->event);
        $this->consumer->acknowledge($received);

        return true;
    }
}
