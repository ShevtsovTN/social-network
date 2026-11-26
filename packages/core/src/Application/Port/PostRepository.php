<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;

interface PostRepository
{
    /**
     * Сохраняет новый пост. Только вставка: повторное сохранение поста с тем же id
     * завершается исключением инфраструктуры.
     */
    public function add(Post $post): void;

    public function findById(PostId $id): ?Post;

    /**
     * Найденные посты среди запрошенных. Неизвестные id пропускаются, порядок не гарантируется.
     *
     * @param list<PostId> $ids
     *
     * @return list<Post>
     */
    public function findByIds(array $ids): array;

    /**
     * Меняет только текст существующего поста; автор и время создания остаются прежними.
     * Неизвестный id: ничего не происходит.
     */
    public function update(Post $post): void;

    /**
     * Удаляет пост. Неизвестный id: ничего не происходит.
     */
    public function delete(PostId $id): void;
}
