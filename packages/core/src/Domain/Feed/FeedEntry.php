<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Feed;

use SocialNetwork\Core\Domain\Post\PostId;

/**
 * Элемент ленты без текста: какой пост и когда создан. Порядок ленты везде «новые первыми»:
 * по времени создания, а при равном времени по id (оба по убыванию).
 */
final readonly class FeedEntry
{
    public function __construct(public PostId $postId, public \DateTimeImmutable $createdAt)
    {
    }

    /**
     * Время создания в микросекундах от начала эпохи (точность PostgreSQL timestamptz).
     */
    public function microseconds(): int
    {
        return (int) $this->createdAt->format('U') * 1_000_000 + (int) $this->createdAt->format('u');
    }

    /**
     * Компаратор для usort: новые первыми.
     */
    public static function compareNewestFirst(self $left, self $right): int
    {
        return $right->microseconds() <=> $left->microseconds()
            ?: strcmp($right->postId->value, $left->postId->value);
    }
}
