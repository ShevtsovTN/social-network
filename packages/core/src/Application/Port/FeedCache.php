<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Кэш лент: у пользователя либо есть «материализованная» лента (последние FeedLimits::CAPACITY постов друзей,
 * новые первыми), либо её нет. Лента может быть материализованной и пустой: это не то же самое, что её отсутствие.
 * Кэш не источник истины: любую ленту можно потерять и собрать заново из БД (FeedSource).
 * При недоступности хранилища методы бросают CacheUnavailable.
 */
interface FeedCache
{
    /**
     * Страница ленты: id постов от $offset, не больше $limit, новые первыми. null, если ленты в кэше нет
     * (промах); [] — лента есть, но страница пуста. Чтение продлевает время жизни ленты.
     *
     * @return list<PostId>|null
     */
    public function read(UserId $reader, int $offset, int $limit): ?array;

    /**
     * Создаёт ленту $reader или дополняет существующую (объединение по id поста), затем обрезает её
     * до FeedLimits::CAPACITY самых новых. Пустой список создаёт пустую ленту.
     *
     * @param list<FeedEntry> $entries
     */
    public function materialize(UserId $reader, array $entries): void;

    /**
     * Дополняет ленты тех из $readers, у кого она уже есть; остальным ничего не создаёт. Обрезает до
     * FeedLimits::CAPACITY. Применяется для fan-out нового поста (много читателей, один элемент)
     * и backfill после добавления друга (один читатель, много элементов).
     *
     * @param list<UserId> $readers
     * @param list<FeedEntry> $entries
     */
    public function mergeIfPresent(array $readers, array $entries): void;

    /**
     * Убирает пост из лент тех из $readers, у кого она есть. Пустая лента остаётся материализованной.
     *
     * @param list<UserId> $readers
     */
    public function removePost(array $readers, PostId $postId): void;

    /**
     * Сбрасывает ленту: при следующем чтении будет промах и пересборка из БД.
     */
    public function forget(UserId $reader): void;
}
