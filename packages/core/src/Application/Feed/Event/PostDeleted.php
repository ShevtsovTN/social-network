<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Feed\Event;

use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Удалён пост: он убирается из лент подписчиков автора. Автор нужен, чтобы найти подписчиков.
 */
final readonly class PostDeleted implements FeedEvent
{
    public function __construct(public PostId $postId, public UserId $authorId)
    {
    }
}
