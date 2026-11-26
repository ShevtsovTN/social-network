<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Feed;

/**
 * Границы ленты постов друзей из контракта (docs/openapi.json).
 */
final class FeedLimits
{
    /** Сколько последних постов друзей хранит лента. */
    public const int CAPACITY = 1000;

    public const int DEFAULT_PAGE_SIZE = 10;

    public const int MAX_PAGE_SIZE = 100;

    private function __construct()
    {
    }
}
