<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use SocialNetwork\Core\Application\Port\FeedEventConsumer;
use SocialNetwork\Core\Application\Port\FeedEventPublisher;

/**
 * Очередь событий ленты с обеих сторон: издатель и потребитель работают над одним и тем же потоком.
 * Для contract-тестов реализация настраивается так, чтобы неподтверждённое событие доставлялось повторно
 * сразу (без ожидания), а каждый тест получал свой пустой поток.
 */
interface FeedEventChannel
{
    public function publisher(): FeedEventPublisher;

    public function consumer(): FeedEventConsumer;
}
