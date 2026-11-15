<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

/**
 * Кэш (FeedCache, PostCache) недоступен: нет соединения, таймаут, ошибка протокола. Реализации кэшей
 * бросают именно его, а use case чтения ленты в этом случае читает данные прямо из БД.
 */
final class CacheUnavailable extends \RuntimeException
{
    public static function because(\Throwable $previous): self
    {
        return new self('Cache is unavailable: ' . $previous->getMessage(), 0, $previous);
    }
}
