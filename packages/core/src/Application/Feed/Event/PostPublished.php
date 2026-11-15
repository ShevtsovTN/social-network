<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Feed\Event;

use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Создан пост: он попадает в уже существующие ленты подписчиков автора.
 */
final readonly class PostPublished implements FeedEvent
{
    public function __construct(public PostId $postId, public UserId $authorId)
    {
    }
}
