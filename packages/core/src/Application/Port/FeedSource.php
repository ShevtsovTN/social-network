<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Источник истины для сборки ленты: что лежит в PostgreSQL. Записей не делает.
 * Один интерфейс, но экземпляров два: холодная пересборка ленты читает с реплики (устаревание допустимо),
 * backfill в воркере читает с primary. Какой экземпляр получает use case, решает composition root.
 */
interface FeedSource
{
    /**
     * Последние посты друзей $reader (тех, кого он добавил), новые первыми: по времени создания, при равном
     * времени по id (оба по убыванию). Не больше $limit. Собственные посты $reader в выдачу не входят.
     *
     * @return list<FeedEntry>
     */
    public function latestFromFriends(UserId $reader, int $limit): array;

    /**
     * Последние посты одного автора, новые первыми, не больше $limit. Порядок тот же.
     *
     * @return list<FeedEntry>
     */
    public function latestByAuthor(UserId $author, int $limit): array;
}
