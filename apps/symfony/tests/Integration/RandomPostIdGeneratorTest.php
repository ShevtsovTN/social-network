<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use PHPUnit\Framework\TestCase;
use SocialNetwork\SymfonyApp\Infrastructure\Security\RandomPostIdGenerator;

final class RandomPostIdGeneratorTest extends TestCase
{
    public function testGeneratesUuidVersion7WithRfcVariant(): void
    {
        $id = (new RandomPostIdGenerator())->generate()->value;

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id);
    }

    public function testEmbedsTheCurrentTimeInMilliseconds(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $id = (new RandomPostIdGenerator())->generate()->value;
        $after = (int) floor(microtime(true) * 1000);

        $milliseconds = (int) hexdec(substr(str_replace('-', '', $id), 0, 12));

        self::assertGreaterThanOrEqual($before, $milliseconds);
        self::assertLessThanOrEqual($after, $milliseconds);
    }

    public function testIdsAreUniqueAndOrderedByTimePrefix(): void
    {
        $generator = new RandomPostIdGenerator();
        $ids = [];

        for ($i = 0; $i < 2000; ++$i) {
            $ids[] = $generator->generate()->value;
        }

        self::assertCount(2000, array_unique($ids));

        $prefixes = array_map(static fn (string $id): string => substr(str_replace('-', '', $id), 0, 12), $ids);
        $sorted = $prefixes;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $prefixes);
    }
}
