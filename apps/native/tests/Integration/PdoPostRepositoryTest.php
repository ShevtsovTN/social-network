<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Tests\Integration;

use SocialNetwork\CoreContract\PostRepositoryContractTest;

/**
 * Идёт на реальный PostgreSQL, каждый тест в транзакции с откатом.
 */
final class PdoPostRepositoryTest extends PostRepositoryContractTest
{
    use UsesPdoFeedStorage;
}
