<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;

final class InMemoryPostRepository implements PostRepository
{
    /** @var array<string, Post> */
    private array $posts = [];

    public function add(Post $post): void
    {
        if (isset($this->posts[$post->id->value])) {
            throw new \LogicException(sprintf('Post %s already exists.', $post->id->value));
        }

        $this->posts[$post->id->value] = $post;
    }

    public function findById(PostId $id): ?Post
    {
        return $this->posts[$id->value] ?? null;
    }

    public function findByIds(array $ids): array
    {
        $found = [];

        foreach ($ids as $id) {
            if (isset($this->posts[$id->value])) {
                $found[] = $this->posts[$id->value];
            }
        }

        return $found;
    }

    public function update(Post $post): void
    {
        if (!isset($this->posts[$post->id->value])) {
            return;
        }

        // Меняется только текст: автор и время создания остаются прежними.
        $this->posts[$post->id->value] = $this->posts[$post->id->value]->edit($post->text);
    }

    public function delete(PostId $id): void
    {
        unset($this->posts[$id->value]);
    }

    /**
     * @return list<Post>
     */
    public function all(): array
    {
        return array_values($this->posts);
    }

    public function count(): int
    {
        return count($this->posts);
    }
}
