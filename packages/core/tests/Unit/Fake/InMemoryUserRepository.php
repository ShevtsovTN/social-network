<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\User\NamePrefix;
use SocialNetwork\Core\Domain\User\User;
use SocialNetwork\Core\Domain\User\UserId;

final class InMemoryUserRepository implements UserRepository
{
    /** @var array<string, User> */
    private array $users = [];

    public function save(User $user): void
    {
        $this->users[$user->id->value] = $user;
    }

    public function findById(UserId $id): ?User
    {
        return $this->users[$id->value] ?? null;
    }

    public function searchByNamePrefix(NamePrefix $firstName, NamePrefix $lastName): array
    {
        $found = array_filter(
            $this->users,
            static fn (User $user): bool => str_starts_with($user->firstName->value, $firstName->value)
                && str_starts_with($user->lastName->value, $lastName->value),
        );
        // Ключи — id в нижнем регистре: порядок строк совпадает с порядком uuid в PostgreSQL.
        ksort($found, SORT_STRING);

        return array_values($found);
    }

    public function count(): int
    {
        return count($this->users);
    }
}
