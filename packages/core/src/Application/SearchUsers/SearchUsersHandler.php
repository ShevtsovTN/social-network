<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\SearchUsers;

use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\User\NamePrefix;

final readonly class SearchUsersHandler implements SearchUsers
{
    public function __construct(private UserRepository $users)
    {
    }

    public function handle(SearchUsersQuery $query): array
    {
        return $this->users->searchByNamePrefix(
            NamePrefix::fromString($query->firstNamePrefix, 'firstName'),
            NamePrefix::fromString($query->lastNamePrefix, 'lastName'),
        );
    }
}
