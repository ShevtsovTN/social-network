<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Application\Feed\Event\ReceivedEvent;

interface FeedEventConsumer
{
    /**
     * Следующее событие очереди или null, если за $waitMilliseconds ничего не пришло. События одного
     * потребителя приходят в порядке публикации. Событие, которое не подтвердили (процесс упал или обработка
     * завершилась ошибкой), приходит снова, спустя время ожидания, заданное реализацией (at-least-once).
     * Нечитаемые события реализация отбрасывает сама.
     */
    public function next(int $waitMilliseconds): ?ReceivedEvent;

    /**
     * Подтверждает обработку: событие больше не будет доставлено.
     */
    public function acknowledge(ReceivedEvent $received): void;
}
