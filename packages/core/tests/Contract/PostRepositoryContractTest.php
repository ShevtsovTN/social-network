<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostText;

/**
 * Контракт порта PostRepository. Каждая реализация (in-memory, PDO в native, PDO в symfony)
 * наследует этот класс и возвращает своё хранилище из createStorage().
 */
abstract class PostRepositoryContractTest extends TestCase
{
    abstract protected function createStorage(): FeedStorage;

    public function testReturnsNullForUnknownId(): void
    {
        $storage = $this->createStorage();

        self::assertNull($storage->posts()->findById(FeedFixture::postId(1)));
    }

    public function testFindsAddedPostById(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1);
        $post = FeedFixture::post(1, 1, 10);

        $storage->posts()->add($post);

        self::assertEquals($post, $storage->posts()->findById($post->id));
    }

    public function testRestoresEveryFieldIncludingMicroseconds(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1);
        $post = FeedFixture::post(1, 1, 10, 123456);
        $storage->posts()->add($post);

        $found = $storage->posts()->findById($post->id);

        self::assertNotNull($found);
        self::assertSame($post->id->value, $found->id->value);
        self::assertSame($post->authorId->value, $found->authorId->value);
        self::assertSame($post->text->value, $found->text->value);
        self::assertSame($post->createdAt->format('Y-m-d H:i:s.u'), $found->createdAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'));
    }

    public function testKeepsLongTextWithLineBreaksQuotesAndUnicode(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1);
        $texts = [
            1 => "Первая строка\nВторая строка с «кавычками», \"двойными\" и 'одинарными'",
            2 => "'; DROP TABLE posts; -- \\ % _ \$1 :name ?",
            3 => str_repeat('я', PostText::MAX_LENGTH),
            4 => 'Эмодзи 🙂 и иероглифы 漢字',
        ];

        foreach ($texts as $number => $text) {
            $storage->posts()->add(FeedFixture::post($number, 1, $number, 0, $text));
        }

        foreach ($texts as $number => $text) {
            self::assertSame($text, $storage->posts()->findById(FeedFixture::postId($number))?->text->value);
        }
    }

    public function testRejectsPostWithTheSameId(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1);
        $storage->posts()->add(FeedFixture::post(1, 1));

        $this->expectException(\Throwable::class);

        $storage->posts()->add(FeedFixture::post(1, 1, 5, 0, 'Другой текст'));
    }

    public function testFindsSeveralPostsByIdsAndSkipsUnknownOnes(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1, 2);
        $first = FeedFixture::post(1, 1, 1);
        $second = FeedFixture::post(2, 2, 2);
        $storage->posts()->add($first);
        $storage->posts()->add($second);
        $storage->posts()->add(FeedFixture::post(3, 1, 3));

        $found = $storage->posts()->findByIds([FeedFixture::postId(2), FeedFixture::postId(99), FeedFixture::postId(1)]);

        $byId = [];
        foreach ($found as $post) {
            $byId[$post->id->value] = $post;
        }

        self::assertCount(2, $found);
        self::assertEquals($first, $byId[$first->id->value]);
        self::assertEquals($second, $byId[$second->id->value]);
    }

    public function testFindByIdsWithNoIdsReturnsNothing(): void
    {
        $storage = $this->createStorage();

        self::assertSame([], $storage->posts()->findByIds([]));
    }

    public function testUpdateChangesOnlyTheText(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1);
        $post = FeedFixture::post(1, 1, 10, 500);
        $storage->posts()->add($post);

        $storage->posts()->update($post->edit(PostText::fromString('Новый текст')));

        $found = $storage->posts()->findById($post->id);
        self::assertNotNull($found);
        self::assertSame('Новый текст', $found->text->value);
        self::assertSame($post->authorId->value, $found->authorId->value);
        self::assertSame($post->createdAt->getTimestamp(), $found->createdAt->getTimestamp());
        self::assertSame($post->createdAt->format('u'), $found->createdAt->format('u'));
    }

    public function testUpdateDoesNotTouchOtherPosts(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1);
        $edited = FeedFixture::post(1, 1, 1);
        $untouched = FeedFixture::post(2, 1, 2);
        $storage->posts()->add($edited);
        $storage->posts()->add($untouched);

        $storage->posts()->update($edited->edit(PostText::fromString('Изменён')));

        self::assertEquals($untouched, $storage->posts()->findById($untouched->id));
    }

    public function testUpdateOfUnknownPostDoesNothing(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1);

        $storage->posts()->update(FeedFixture::post(1, 1));

        self::assertNull($storage->posts()->findById(FeedFixture::postId(1)));
    }

    public function testDeleteRemovesOnlyThatPost(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1);
        $deleted = FeedFixture::post(1, 1, 1);
        $kept = FeedFixture::post(2, 1, 2);
        $storage->posts()->add($deleted);
        $storage->posts()->add($kept);

        $storage->posts()->delete($deleted->id);

        self::assertNull($storage->posts()->findById($deleted->id));
        self::assertEquals($kept, $storage->posts()->findById($kept->id));
    }

    public function testDeleteOfUnknownPostDoesNothing(): void
    {
        $storage = $this->createStorage();
        FeedFixture::addUsers($storage, 1);
        $post = FeedFixture::post(1, 1);
        $storage->posts()->add($post);

        $storage->posts()->delete(FeedFixture::postId(99));

        self::assertEquals($post, $storage->posts()->findById($post->id));
    }
}
