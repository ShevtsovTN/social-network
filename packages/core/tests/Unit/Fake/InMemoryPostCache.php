<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\PostCache;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;

final class InMemoryPostCache implements PostCache
{
    /** @var array<string, Post> */
    private array $posts = [];

    public function findMany(array $ids): array
    {
        $found = [];

        foreach ($ids as $id) {
            if (isset($this->posts[$id->value])) {
                $found[$id->value] = $this->posts[$id->value];
            }
        }

        return $found;
    }

    public function putMany(array $posts): void
    {
        foreach ($posts as $post) {
            $this->posts[$post->id->value] = $post;
        }
    }

    public function forget(PostId $id): void
    {
        unset($this->posts[$id->value]);
    }

    public function has(PostId $id): bool
    {
        return isset($this->posts[$id->value]);
    }
}
