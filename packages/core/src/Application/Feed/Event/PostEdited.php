<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Feed\Event;

use SocialNetwork\Core\Domain\Post\PostId;

/**
 * Изменён текст поста: кэш тела поста обновляется.
 */
final readonly class PostEdited implements FeedEvent
{
    public function __construct(public PostId $postId)
    {
    }
}
