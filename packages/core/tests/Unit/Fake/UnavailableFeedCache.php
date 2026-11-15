<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\CacheUnavailable;
use SocialNetwork\Core\Application\Port\FeedCache;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Кэш лент, до которого нельзя достучаться: каждый вызов бросает CacheUnavailable.
 */
final class UnavailableFeedCache implements FeedCache
{
    public function read(UserId $reader, int $offset, int $limit): ?array
    {
        throw $this->down();
    }

    public function materialize(UserId $reader, array $entries): void
    {
        throw $this->down();
    }

    public function mergeIfPresent(array $readers, array $entries): void
    {
        throw $this->down();
    }

    public function removePost(array $readers, PostId $postId): void
    {
        throw $this->down();
    }

    public function forget(UserId $reader): void
    {
        throw $this->down();
    }

    private function down(): CacheUnavailable
    {
        return CacheUnavailable::because(new \RuntimeException('connection refused'));
    }
}
