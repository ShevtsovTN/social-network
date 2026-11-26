<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use SocialNetwork\CoreContract\FriendshipRepositoryContractTest;

/**
 * Идёт на реальный PostgreSQL, каждый тест в транзакции с откатом.
 */
final class PdoFriendshipRepositoryTest extends FriendshipRepositoryContractTest
{
    use UsesPdoFeedStorage;
}
