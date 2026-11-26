<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\CreatePost\CreatePostCommand;
use SocialNetwork\Core\Application\CreatePost\CreatePostHandler;
use SocialNetwork\Core\Application\Feed\Event\PostPublished;
use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Tests\Unit\Fake\FixedClock;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedEventChannel;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryPostRepository;
use SocialNetwork\Core\Tests\Unit\Fake\SequentialPostIdGenerator;
use SocialNetwork\CoreContract\FeedFixture;

final class CreatePostHandlerTest extends TestCase
{
    private InMemoryPostRepository $posts;
    private InMemoryFeedEventChannel $events;
    private CreatePostHandler $handler;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->posts = new InMemoryPostRepository();
        $this->events = new InMemoryFeedEventChannel();
        $this->now = new \DateTimeImmutable('2026-11-15 12:00:00.123456', new \DateTimeZone('UTC'));
        $this->handler = new CreatePostHandler($this->posts, new SequentialPostIdGenerator(), new FixedClock($this->now), $this->events);
    }

    public function testStoresThePostOfTheActorWithGeneratedIdAndCurrentTime(): void
    {
        $id = $this->handler->handle(new CreatePostCommand(FeedFixture::userId(1), 'Мой первый пост'));

        $post = $this->posts->findById($id);
        self::assertNotNull($post);
        self::assertSame('10000000-0000-4000-8000-000000000001', $id->value);
        self::assertTrue($post->authorId->equals(FeedFixture::userId(1)));
        self::assertSame('Мой первый пост', $post->text->value);
        self::assertEquals($this->now, $post->createdAt);
    }

    public function testNormalizesTheText(): void
    {
        $id = $this->handler->handle(new CreatePostCommand(FeedFixture::userId(1), "  Пост с пробелами \n"));

        self::assertSame('Пост с пробелами', $this->posts->findById($id)?->text->value);
    }

    public function testPublishesTheEventWithPostAndAuthor(): void
    {
        $id = $this->handler->handle(new CreatePostCommand(FeedFixture::userId(1), 'Пост'));

        self::assertEquals([new PostPublished($id, FeedFixture::userId(1))], $this->events->pending());
    }

    public function testEveryPostGetsItsOwnId(): void
    {
        $first = $this->handler->handle(new CreatePostCommand(FeedFixture::userId(1), 'Первый'));
        $second = $this->handler->handle(new CreatePostCommand(FeedFixture::userId(1), 'Второй'));

        self::assertFalse($first->equals($second));
        self::assertSame(2, $this->posts->count());
    }

    public function testInvalidTextStoresNothingAndPublishesNothing(): void
    {
        try {
            $this->handler->handle(new CreatePostCommand(FeedFixture::userId(1), '   '));
            self::fail('InvalidPostData expected.');
        } catch (InvalidPostData $exception) {
            self::assertSame('text', $exception->field);
        }

        self::assertSame(0, $this->posts->count());
        self::assertSame([], $this->events->pending());
    }
}
