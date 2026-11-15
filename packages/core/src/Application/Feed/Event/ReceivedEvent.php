<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Feed\Event;

/**
 * Событие, полученное из очереди. $deliveryId непрозрачен для core: по нему реализация очереди
 * подтверждает именно эту доставку (FeedEventConsumer::acknowledge).
 */
final readonly class ReceivedEvent
{
    public function __construct(public FeedEvent $event, public string $deliveryId)
    {
    }
}
