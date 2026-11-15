<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\Feed\FeedLimits;
use SocialNetwork\Core\Domain\Feed\FeedPageRequest;
use SocialNetwork\Core\Domain\Feed\InvalidFeedPage;

final class FeedPageRequestTest extends TestCase
{
    public function testMissingParametersGetDefaults(): void
    {
        $page = FeedPageRequest::fromInput(null, null);

        self::assertSame(0, $page->offset);
        self::assertSame(FeedLimits::DEFAULT_PAGE_SIZE, $page->limit);
    }

    public function testParsesValidParameters(): void
    {
        $page = FeedPageRequest::fromInput('25', '100');

        self::assertSame(25, $page->offset);
        self::assertSame(100, $page->limit);
    }

    public function testAcceptsBoundaryValues(): void
    {
        self::assertSame(0, FeedPageRequest::fromInput('0', '1')->offset);
        self::assertSame(1, FeedPageRequest::fromInput('0', '1')->limit);
    }

    public function testLeadingZerosAreFine(): void
    {
        $page = FeedPageRequest::fromInput('007', '010');

        self::assertSame(7, $page->offset);
        self::assertSame(10, $page->limit);
    }

    public function testHugeOffsetIsClampedToTheFeedCapacity(): void
    {
        self::assertSame(FeedLimits::CAPACITY, FeedPageRequest::fromInput('5000', null)->offset);
        self::assertSame(FeedLimits::CAPACITY, FeedPageRequest::fromInput('99999999999999999999999999', null)->offset);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidOffsets(): iterable
    {
        yield 'negative' => ['-1'];
        yield 'letters' => ['abc'];
        yield 'empty' => [''];
        yield 'fraction' => ['1.5'];
        yield 'plus sign' => ['+1'];
        yield 'spaces' => [' 1'];
        yield 'trailing line break' => ["1\n"];
        yield 'exponent' => ['1e3'];
    }

    #[DataProvider('invalidOffsets')]
    public function testRejectsInvalidOffset(string $offset): void
    {
        try {
            FeedPageRequest::fromInput($offset, '10');
            self::fail('InvalidFeedPage expected.');
        } catch (InvalidFeedPage $exception) {
            self::assertSame('offset', $exception->field);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidLimits(): iterable
    {
        yield 'zero' => ['0'];
        yield 'above maximum' => ['101'];
        yield 'huge' => ['99999999999999999999999999'];
        yield 'negative' => ['-5'];
        yield 'letters' => ['many'];
        yield 'empty' => [''];
        yield 'fraction' => ['2.5'];
    }

    #[DataProvider('invalidLimits')]
    public function testRejectsInvalidLimit(string $limit): void
    {
        try {
            FeedPageRequest::fromInput('0', $limit);
            self::fail('InvalidFeedPage expected.');
        } catch (InvalidFeedPage $exception) {
            self::assertSame('limit', $exception->field);
        }
    }

    public function testReportsOffsetBeforeLimitWhenBothAreInvalid(): void
    {
        try {
            FeedPageRequest::fromInput('-1', '0');
            self::fail('InvalidFeedPage expected.');
        } catch (InvalidFeedPage $exception) {
            self::assertSame('offset', $exception->field);
        }
    }
}
