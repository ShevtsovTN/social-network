<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\Post\PostText;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Пользователи, посты и элементы ленты для contract-тестов. Номера дают возрастающие id и время:
 * чем больше номер поста и смещение, тем новее пост. Доступен приложениям вместе с core.
 */
final class FeedFixture
{
    private const int BASE_TIMESTAMP = 1_800_000_000;

    private function __construct()
    {
    }

    public static function userId(int $number): UserId
    {
        return UserId::fromString(sprintf('a0000000-0000-4000-8000-%012d', $number));
    }

    public static function postId(int $number): PostId
    {
        return PostId::fromString(sprintf('b0000000-0000-4000-8000-%012d', $number));
    }

    /**
     * Момент времени в UTC: BASE_TIMESTAMP + $seconds секунд и $microseconds микросекунд.
     */
    public static function time(int $seconds, int $microseconds = 0): \DateTimeImmutable
    {
        $time = \DateTimeImmutable::createFromFormat('U.u', sprintf('%d.%06d', self::BASE_TIMESTAMP + $seconds, $microseconds));
        \assert($time instanceof \DateTimeImmutable);

        return $time->setTimezone(new \DateTimeZone('UTC'));
    }

    public static function post(
        int $postNumber,
        int $authorNumber,
        int $seconds = 0,
        int $microseconds = 0,
        string $text = 'Текст поста',
    ): Post {
        return Post::reconstitute(
            self::postId($postNumber),
            self::userId($authorNumber),
            PostText::fromString($text),
            self::time($seconds, $microseconds),
        );
    }

    public static function entry(int $postNumber, int $seconds = 0, int $microseconds = 0): FeedEntry
    {
        return new FeedEntry(self::postId($postNumber), self::time($seconds, $microseconds));
    }

    /**
     * Сохраняет пользователей с указанными номерами (внешние ключи требуют существующих пользователей).
     */
    public static function addUsers(FeedStorage $storage, int ...$numbers): void
    {
        foreach ($numbers as $number) {
            $storage->users()->save(UserFixture::create(self::userId($number)->value));
        }
    }

    /**
     * @param list<FeedEntry> $entries
     *
     * @return list<string>
     */
    public static function postIds(array $entries): array
    {
        return array_map(static fn (FeedEntry $entry): string => $entry->postId->value, $entries);
    }

    /**
     * @param list<PostId> $ids
     *
     * @return list<string>
     */
    public static function values(array $ids): array
    {
        return array_map(static fn (PostId $id): string => $id->value, $ids);
    }
}
