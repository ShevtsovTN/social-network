<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\SearchUsers;

use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\User;

interface SearchUsers
{
    /**
     * Анкеты, у которых имя и фамилия начинаются с заданных префиксов, отсортированные по id.
     *
     * @return list<User>
     *
     * @throws InvalidUserData если префикс пустой, слишком длинный или содержит недопустимые символы
     */
    public function handle(SearchUsersQuery $query): array;
}
