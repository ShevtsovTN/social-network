<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\UserIdGenerator;
use SocialNetwork\Core\Domain\User\UserId;

final class SequentialUserIdGenerator implements UserIdGenerator
{
    private int $counter = 0;

    public function generate(): UserId
    {
        return UserId::fromString(sprintf('00000000-0000-4000-8000-%012d', ++$this->counter));
    }
}
