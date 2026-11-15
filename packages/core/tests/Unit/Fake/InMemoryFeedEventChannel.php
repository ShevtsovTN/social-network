<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Feed\Event\FeedEvent;
use SocialNetwork\Core\Application\Feed\Event\ReceivedEvent;
use SocialNetwork\Core\Application\Port\FeedEventConsumer;
use SocialNetwork\Core\Application\Port\FeedEventPublisher;
use SocialNetwork\CoreContract\FeedEventChannel;

/**
 * Очередь в памяти: издатель, потребитель и сам канал в одном объекте. Неподтверждённое событие
 * доставляется повторно сразу, как требуют contract-тесты.
 */
final class InMemoryFeedEventChannel implements FeedEventChannel, FeedEventPublisher, FeedEventConsumer
{
    /** @var array<string, FeedEvent> deliveryId => событие, ждущее подтверждения; порядок публикации */
    private array $pending = [];
    private int $counter = 0;

    public function publisher(): FeedEventPublisher
    {
        return $this;
    }

    public function consumer(): FeedEventConsumer
    {
        return $this;
    }

    public function publish(FeedEvent $event): void
    {
        $this->pending[(string) ++$this->counter] = $event;
    }

    public function next(int $waitMilliseconds): ?ReceivedEvent
    {
        $deliveryId = array_key_first($this->pending);

        if ($deliveryId === null) {
            return null;
        }

        return new ReceivedEvent($this->pending[$deliveryId], (string) $deliveryId);
    }

    public function acknowledge(ReceivedEvent $received): void
    {
        unset($this->pending[$received->deliveryId]);
    }

    /**
     * События, которые ещё не подтверждены (для проверок в тестах).
     *
     * @return list<FeedEvent>
     */
    public function pending(): array
    {
        return array_values($this->pending);
    }
}
