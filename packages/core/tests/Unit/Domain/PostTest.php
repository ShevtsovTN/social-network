<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Post\PostText;
use SocialNetwork\CoreContract\FeedFixture;

final class PostTest extends TestCase
{
    public function testEditChangesOnlyTheText(): void
    {
        $post = FeedFixture::post(1, 7, 10, 250);

        $edited = $post->edit(PostText::fromString('Новый текст'));

        self::assertSame('Новый текст', $edited->text->value);
        self::assertSame($post->id->value, $edited->id->value);
        self::assertSame($post->authorId->value, $edited->authorId->value);
        self::assertEquals($post->createdAt, $edited->createdAt);
    }

    public function testEditLeavesTheOriginalUntouched(): void
    {
        $post = FeedFixture::post(1, 7, 10, 0, 'Старый текст');

        $post->edit(PostText::fromString('Новый текст'));

        self::assertSame('Старый текст', $post->text->value);
    }

    public function testIsWrittenByItsAuthorOnly(): void
    {
        $post = FeedFixture::post(1, 7);

        self::assertTrue($post->isWrittenBy(FeedFixture::userId(7)));
        self::assertFalse($post->isWrittenBy(FeedFixture::userId(8)));
    }
}
