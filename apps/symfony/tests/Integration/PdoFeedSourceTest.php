<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use SocialNetwork\CoreContract\FeedSourceContractTest;

/**
 * Идёт на реальный PostgreSQL, каждый тест в транзакции с откатом.
 */
final class PdoFeedSourceTest extends FeedSourceContractTest
{
    use UsesPdoFeedStorage;
}
