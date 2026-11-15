<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Application\Feed\Event\FeedEvent;

interface FeedEventPublisher
{
    /**
     * Ставит событие в очередь. Best-effort: никогда не бросает исключений. Если очередь недоступна, событие
     * теряется (процесс может упасть и между записью в БД и публикацией), ленты потом лечатся пересборкой.
     */
    public function publish(FeedEvent $event): void;
}
