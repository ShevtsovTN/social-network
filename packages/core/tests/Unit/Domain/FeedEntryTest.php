<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Feed\FeedEntry;
use SocialNetwork\CoreContract\FeedFixture;

final class FeedEntryTest extends TestCase
{
    public function testMicrosecondsCombineSecondsAndFraction(): void
    {
        $entry = new FeedEntry(FeedFixture::postId(1), new \DateTimeImmutable('2026-01-01 00:00:01.000002', new \DateTimeZone('UTC')));

        self::assertSame(1_767_225_601_000_002, $entry->microseconds());
    }

    public function testMicrosecondsDoNotDependOnTheTimeZone(): void
    {
        $utc = new FeedEntry(FeedFixture::postId(1), new \DateTimeImmutable('2026-01-01 12:00:00.5', new \DateTimeZone('UTC')));
        $moscow = new FeedEntry(FeedFixture::postId(1), new \DateTimeImmutable('2026-01-01 15:00:00.5', new \DateTimeZone('Europe/Moscow')));

        self::assertSame($utc->microseconds(), $moscow->microseconds());
    }

    public function testNewestComeFirstAndTiesBreakByIdDescending(): void
    {
        $entries = [
            FeedFixture::entry(1, 10),
            FeedFixture::entry(2, 30),
            FeedFixture::entry(3, 30),
            FeedFixture::entry(4, 20, 7),
            FeedFixture::entry(5, 20, 9),
        ];

        usort($entries, FeedEntry::compareNewestFirst(...));

        self::assertSame(
            [FeedFixture::postId(3)->value, FeedFixture::postId(2)->value, FeedFixture::postId(5)->value, FeedFixture::postId(4)->value, FeedFixture::postId(1)->value],
            FeedFixture::postIds($entries),
        );
    }
}
