<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetFeed;

use SocialNetwork\Core\Domain\Feed\InvalidFeedPage;
use SocialNetwork\Core\Domain\Post\Post;

interface GetFeed
{
    /**
     * Страница ленты постов друзей, новые первыми. Лента держит не больше FeedLimits::CAPACITY последних постов:
     * страница за этой границей пуста. Нет друзей или постов — пустой список. Если кэш недоступен,
     * лента читается прямо из БД. Лента итогово согласована: свежий пост друга может появиться не сразу.
     *
     * @return list<Post>
     *
     * @throws InvalidFeedPage если offset или limit не целые или вне границ (field = offset или limit)
     */
    public function handle(GetFeedQuery $query): array;
}
