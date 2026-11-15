<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Feed\Event\PostEdited;
use SocialNetwork\Core\Application\UpdatePost\UpdatePostCommand;
use SocialNetwork\Core\Application\UpdatePost\UpdatePostHandler;
use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\PostNotFound;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedEventChannel;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryPostRepository;
use SocialNetwork\CoreContract\FeedFixture;

final class UpdatePostHandlerTest extends TestCase
{
    private InMemoryPostRepository $posts;
    private InMemoryFeedEventChannel $events;
    private UpdatePostHandler $handler;

    protected function setUp(): void
    {
        $this->posts = new InMemoryPostRepository();
        $this->events = new InMemoryFeedEventChannel();
        $this->handler = new UpdatePostHandler($this->posts, $this->events);
        $this->posts->add(FeedFixture::post(1, 1, 10, 500, 'Старый текст'));
    }

    public function testAuthorChangesTheTextAndKeepsEverythingElse(): void
    {
        $this->handler->handle(new UpdatePostCommand(FeedFixture::userId(1), FeedFixture::postId(1)->value, "  Новый текст \n"));

        $post = $this->posts->findById(FeedFixture::postId(1));
        self::assertNotNull($post);
        self::assertSame('Новый текст', $post->text->value);
        self::assertTrue($post->authorId->equals(FeedFixture::userId(1)));
        self::assertEquals(FeedFixture::time(10, 500), $post->createdAt);
    }

    public function testPublishesTheEvent(): void
    {
        $this->handler->handle(new UpdatePostCommand(FeedFixture::userId(1), FeedFixture::postId(1)->value, 'Новый текст'));

        self::assertEquals([new PostEdited(FeedFixture::postId(1))], $this->events->pending());
    }

    public function testPostIdIsCaseInsensitive(): void
    {
        $this->handler->handle(new UpdatePostCommand(FeedFixture::userId(1), strtoupper(FeedFixture::postId(1)->value), 'Новый текст'));

        self::assertSame('Новый текст', $this->posts->findById(FeedFixture::postId(1))?->text->value);
    }

    public function testForeignPostLooksLikeAMissingOne(): void
    {
        try {
            $this->handler->handle(new UpdatePostCommand(FeedFixture::userId(2), FeedFixture::postId(1)->value, 'Взлом'));
            self::fail('PostNotFound expected.');
        } catch (PostNotFound) {
            // ожидаемо
        }

        self::assertSame('Старый текст', $this->posts->findById(FeedFixture::postId(1))?->text->value);
        self::assertSame([], $this->events->pending());
    }

    public function testMissingPostIsNotFound(): void
    {
        $this->expectException(PostNotFound::class);

        $this->handler->handle(new UpdatePostCommand(FeedFixture::userId(1), FeedFixture::postId(99)->value, 'Текст'));
    }

    public function testMalformedPostIdIsReportedAsId(): void
    {
        try {
            $this->handler->handle(new UpdatePostCommand(FeedFixture::userId(1), 'not-a-uuid', 'Текст'));
            self::fail('InvalidPostData expected.');
        } catch (InvalidPostData $exception) {
            self::assertSame('id', $exception->field);
        }
    }

    public function testInvalidTextIsReportedAsTextAndChangesNothing(): void
    {
        try {
            $this->handler->handle(new UpdatePostCommand(FeedFixture::userId(1), FeedFixture::postId(1)->value, ''));
            self::fail('InvalidPostData expected.');
        } catch (InvalidPostData $exception) {
            self::assertSame('text', $exception->field);
        }

        self::assertSame('Старый текст', $this->posts->findById(FeedFixture::postId(1))?->text->value);
        self::assertSame([], $this->events->pending());
    }

    public function testMalformedIdIsReportedBeforeInvalidText(): void
    {
        try {
            $this->handler->handle(new UpdatePostCommand(FeedFixture::userId(1), 'not-a-uuid', ''));
            self::fail('InvalidPostData expected.');
        } catch (InvalidPostData $exception) {
            self::assertSame('id', $exception->field);
        }
    }

    public function testInvalidTextOfAForeignPostIsReportedBeforeNotFound(): void
    {
        $this->expectException(InvalidPostData::class);

        $this->handler->handle(new UpdatePostCommand(FeedFixture::userId(2), FeedFixture::postId(1)->value, ''));
    }
}
