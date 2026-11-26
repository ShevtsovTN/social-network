<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\DeletePost\DeletePostCommand;
use SocialNetwork\Core\Application\DeletePost\DeletePostHandler;
use SocialNetwork\Core\Application\Feed\Event\PostDeleted;
use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\PostNotFound;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryFeedEventChannel;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryPostRepository;
use SocialNetwork\CoreContract\FeedFixture;

final class DeletePostHandlerTest extends TestCase
{
    private InMemoryPostRepository $posts;
    private InMemoryFeedEventChannel $events;
    private DeletePostHandler $handler;

    protected function setUp(): void
    {
        $this->posts = new InMemoryPostRepository();
        $this->events = new InMemoryFeedEventChannel();
        $this->handler = new DeletePostHandler($this->posts, $this->events);
        $this->posts->add(FeedFixture::post(1, 1));
        $this->posts->add(FeedFixture::post(2, 1));
    }

    public function testAuthorDeletesOnlyThatPost(): void
    {
        $this->handler->handle(new DeletePostCommand(FeedFixture::userId(1), FeedFixture::postId(1)->value));

        self::assertNull($this->posts->findById(FeedFixture::postId(1)));
        self::assertNotNull($this->posts->findById(FeedFixture::postId(2)));
    }

    public function testPublishesTheEventWithTheAuthor(): void
    {
        $this->handler->handle(new DeletePostCommand(FeedFixture::userId(1), FeedFixture::postId(1)->value));

        self::assertEquals([new PostDeleted(FeedFixture::postId(1), FeedFixture::userId(1))], $this->events->pending());
    }

    public function testForeignPostLooksLikeAMissingOne(): void
    {
        try {
            $this->handler->handle(new DeletePostCommand(FeedFixture::userId(2), FeedFixture::postId(1)->value));
            self::fail('PostNotFound expected.');
        } catch (PostNotFound) {
            // ожидаемо
        }

        self::assertNotNull($this->posts->findById(FeedFixture::postId(1)));
        self::assertSame([], $this->events->pending());
    }

    public function testDeletingTwiceIsNotFoundTheSecondTime(): void
    {
        $command = new DeletePostCommand(FeedFixture::userId(1), FeedFixture::postId(1)->value);
        $this->handler->handle($command);

        $this->expectException(PostNotFound::class);

        $this->handler->handle($command);
    }

    public function testMalformedPostIdIsReportedAsId(): void
    {
        try {
            $this->handler->handle(new DeletePostCommand(FeedFixture::userId(1), 'not-a-uuid'));
            self::fail('InvalidPostData expected.');
        } catch (InvalidPostData $exception) {
            self::assertSame('id', $exception->field);
        }

        self::assertSame(2, $this->posts->count());
    }
}
