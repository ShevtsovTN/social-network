<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\SearchUsers;

final readonly class SearchUsersQuery
{
    public function __construct(
        public string $firstNamePrefix,
        public string $lastNamePrefix,
    ) {
    }
}
