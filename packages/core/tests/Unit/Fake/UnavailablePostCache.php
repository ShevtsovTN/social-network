<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\CacheUnavailable;
use SocialNetwork\Core\Application\Port\PostCache;
use SocialNetwork\Core\Domain\Post\PostId;

/**
 * Кэш тел постов, до которого нельзя достучаться: каждый вызов бросает CacheUnavailable.
 */
final class UnavailablePostCache implements PostCache
{
    public function findMany(array $ids): array
    {
        throw $this->down();
    }

    public function putMany(array $posts): void
    {
        throw $this->down();
    }

    public function forget(PostId $id): void
    {
        throw $this->down();
    }

    private function down(): CacheUnavailable
    {
        return CacheUnavailable::because(new \RuntimeException('connection refused'));
    }
}
