<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\ProcessNextFeedEvent;

final readonly class ProcessNextFeedEventCommand
{
    public function __construct(public int $waitMilliseconds)
    {
    }
}
