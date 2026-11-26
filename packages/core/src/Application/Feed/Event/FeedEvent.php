<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Feed\Event;

/**
 * Событие, после которого нужно обновить ленты. Публикуется use case'ами после записи в PostgreSQL
 * и обрабатывается воркером асинхронно (ApplyFeedEvent). Реализаций ровно пять, см. соседние классы.
 */
interface FeedEvent
{
}
