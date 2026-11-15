<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\Friend\Friendship;
use SocialNetwork\Core\Domain\User\UserId;

interface FriendshipRepository
{
    /**
     * Сохраняет дружбу. Идемпотентно: повторное добавление той же пары ничего не меняет.
     */
    public function add(Friendship $friendship): void;

    /**
     * Удаляет дружбу «$userId добавил $friendId». Идемпотентно: если её не было, ничего не происходит.
     */
    public function remove(UserId $userId, UserId $friendId): void;

    /**
     * Подписчики автора: те, кто добавил $authorId в друзья. Отсортированы по id (побайтово по нижнему регистру),
     * только строго после $after, не больше $limit. Для обхода порциями: следующий вызов получает последний id порции.
     *
     * @return list<UserId>
     */
    public function followerIdsOf(UserId $authorId, int $limit, ?UserId $after = null): array;
}
