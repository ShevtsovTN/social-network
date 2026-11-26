<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\PostIdGenerator;
use SocialNetwork\Core\Domain\Post\PostId;

final class SequentialPostIdGenerator implements PostIdGenerator
{
    private int $counter = 0;

    public function generate(): PostId
    {
        return PostId::fromString(sprintf('10000000-0000-4000-8000-%012d', ++$this->counter));
    }
}
