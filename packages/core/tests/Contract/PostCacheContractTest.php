<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Port\PostCache;
use SocialNetwork\Core\Domain\Post\PostText;

/**
 * Контракт порта PostCache. Реализация возвращает из createPostCache() пустой кэш.
 */
abstract class PostCacheContractTest extends TestCase
{
    abstract protected function createPostCache(): PostCache;

    public function testFindsNothingInAnEmptyCache(): void
    {
        $cache = $this->createPostCache();

        self::assertSame([], $cache->findMany([FeedFixture::postId(1), FeedFixture::postId(2)]));
    }

    public function testFindManyWithoutIdsReturnsNothing(): void
    {
        self::assertSame([], $this->createPostCache()->findMany([]));
    }

    public function testReturnsStoredPostsKeyedById(): void
    {
        $cache = $this->createPostCache();
        $first = FeedFixture::post(1, 1, 10, 123456);
        $second = FeedFixture::post(2, 2, 20);

        $cache->putMany([$first, $second]);

        $found = $cache->findMany([FeedFixture::postId(2), FeedFixture::postId(1)]);
        self::assertEqualsCanonicalizing([$first->id->value, $second->id->value], array_keys($found));
        self::assertEquals($first, $found[$first->id->value]);
        self::assertEquals($second, $found[$second->id->value]);
        self::assertSame('123456', $found[$first->id->value]->createdAt->format('u'));
    }

    public function testReturnsOnlyTheHits(): void
    {
        $cache = $this->createPostCache();
        $post = FeedFixture::post(1, 1);
        $cache->putMany([$post]);

        $found = $cache->findMany([FeedFixture::postId(1), FeedFixture::postId(2)]);

        self::assertSame([$post->id->value], array_keys($found));
    }

    public function testPutReplacesAnExistingPost(): void
    {
        $cache = $this->createPostCache();
        $post = FeedFixture::post(1, 1);
        $cache->putMany([$post]);

        $cache->putMany([$post->edit(PostText::fromString('Исправленный текст'))]);

        self::assertSame('Исправленный текст', $cache->findMany([$post->id])[$post->id->value]->text->value);
    }

    public function testPutWithoutPostsDoesNothing(): void
    {
        $cache = $this->createPostCache();

        $cache->putMany([]);

        self::assertSame([], $cache->findMany([FeedFixture::postId(1)]));
    }

    public function testForgetRemovesOnlyThatPost(): void
    {
        $cache = $this->createPostCache();
        $cache->putMany([FeedFixture::post(1, 1), FeedFixture::post(2, 1)]);

        $cache->forget(FeedFixture::postId(1));

        self::assertSame([FeedFixture::postId(2)->value], array_keys($cache->findMany([FeedFixture::postId(1), FeedFixture::postId(2)])));
    }

    public function testForgettingAMissingPostDoesNothing(): void
    {
        $cache = $this->createPostCache();

        $cache->forget(FeedFixture::postId(1));

        self::assertSame([], $cache->findMany([FeedFixture::postId(1)]));
    }

    public function testKeepsTextWithLineBreaksQuotesAndUnicode(): void
    {
        $cache = $this->createPostCache();
        $texts = [
            1 => "Первая строка\nВторая «строка», \"кавычки\" и 'апострофы'",
            2 => "'; DROP TABLE posts; -- \\ % _ \$1 :name ?",
            3 => str_repeat('я', PostText::MAX_LENGTH),
            4 => 'Эмодзи 🙂 и иероглифы 漢字',
        ];
        foreach ($texts as $number => $text) {
            $cache->putMany([FeedFixture::post($number, 1, $number, 0, $text)]);
        }

        $found = $cache->findMany(array_map(FeedFixture::postId(...), array_keys($texts)));

        foreach ($texts as $number => $text) {
            self::assertSame($text, $found[FeedFixture::postId($number)->value]->text->value);
        }
    }
}
