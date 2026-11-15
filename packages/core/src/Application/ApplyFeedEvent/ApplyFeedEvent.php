<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\ApplyFeedEvent;

use SocialNetwork\Core\Application\Feed\Event\FeedEvent;

interface ApplyFeedEvent
{
    /**
     * Обновляет кэши ленты по событию. Идемпотентно: событие может прийти повторно (at-least-once).
     * Ошибки инфраструктуры (кэш или БД недоступны) пробрасываются, чтобы событие не подтверждалось
     * и пришло снова.
     */
    public function handle(FeedEvent $event): void;
}
