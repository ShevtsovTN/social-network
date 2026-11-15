<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;

/**
 * Кэш тел постов: лента хранит только id, тексты лежат отдельно, поэтому изменение текста
 * обновляет одну запись для всех лент. Не источник истины. При недоступности хранилища бросает CacheUnavailable.
 */
interface PostCache
{
    /**
     * Найденные в кэше посты, ключ — значение PostId. Отсутствующие id в результат не входят.
     *
     * @param list<PostId> $ids
     *
     * @return array<string, Post>
     */
    public function findMany(array $ids): array;

    /**
     * @param list<Post> $posts
     */
    public function putMany(array $posts): void;

    public function forget(PostId $id): void;
}
