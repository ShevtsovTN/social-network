<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Application;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\GetPost\GetPostHandler;
use SocialNetwork\Core\Application\GetPost\GetPostQuery;
use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\PostNotFound;
use SocialNetwork\Core\Tests\Unit\Fake\InMemoryPostRepository;
use SocialNetwork\CoreContract\FeedFixture;

final class GetPostHandlerTest extends TestCase
{
    private InMemoryPostRepository $posts;
    private GetPostHandler $handler;

    protected function setUp(): void
    {
        $this->posts = new InMemoryPostRepository();
        $this->handler = new GetPostHandler($this->posts);
    }

    public function testReturnsAnExistingPost(): void
    {
        $post = FeedFixture::post(1, 1, 10, 0, 'Текст');
        $this->posts->add($post);

        self::assertEquals($post, $this->handler->handle(new GetPostQuery(FeedFixture::postId(1)->value)));
    }

    public function testIdLookupIsCaseInsensitive(): void
    {
        $this->posts->add(FeedFixture::post(1, 1));

        $found = $this->handler->handle(new GetPostQuery(strtoupper(FeedFixture::postId(1)->value)));

        self::assertSame(FeedFixture::postId(1)->value, $found->id->value);
    }

    public function testThrowsWhenThePostDoesNotExist(): void
    {
        $this->expectException(PostNotFound::class);

        $this->handler->handle(new GetPostQuery(FeedFixture::postId(99)->value));
    }

    public function testMalformedIdIsReportedAsId(): void
    {
        try {
            $this->handler->handle(new GetPostQuery('not-a-uuid'));
            self::fail('InvalidPostData expected.');
        } catch (InvalidPostData $exception) {
            self::assertSame('id', $exception->field);
        }
    }
}
