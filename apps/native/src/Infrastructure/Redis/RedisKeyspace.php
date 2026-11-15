<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Infrastructure\Redis;

use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Имена ключей Redis. Префикс у каждого приложения свой (native или symfony), поэтому приложения не делят
 * ни кэш, ни очередь событий.
 */
final readonly class RedisKeyspace
{
    public function __construct(private string $prefix)
    {
        if ($prefix === '') {
            throw new \InvalidArgumentException('Redis key prefix must not be empty.');
        }
    }

    public function feed(UserId $reader): string
    {
        return $this->prefix . ':feed:' . $reader->value;
    }

    public function post(PostId $postId): string
    {
        return $this->prefix . ':post:' . $postId->value;
    }

    public function eventStream(): string
    {
        return $this->prefix . ':feed-events';
    }

    /**
     * Шаблон для SCAN: все ключи этого приложения.
     */
    public function pattern(): string
    {
        return $this->prefix . ':*';
    }
}
