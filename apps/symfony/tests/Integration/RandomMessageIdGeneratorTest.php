<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use SocialNetwork\Core\Application\Port\MessageIdGenerator;
use SocialNetwork\CoreContract\MessageIdGeneratorContractTest;
use SocialNetwork\SymfonyApp\Infrastructure\Security\RandomMessageIdGenerator;

final class RandomMessageIdGeneratorTest extends MessageIdGeneratorContractTest
{
    protected function createGenerator(): MessageIdGenerator
    {
        return new RandomMessageIdGenerator();
    }

    public function testGeneratesUuidVersion7WithRfcVariant(): void
    {
        $id = $this->createGenerator()->generate()->value;

        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D', $id);
    }

    public function testEmbedsTheCurrentTimeInMilliseconds(): void
    {
        $before = (int) floor(microtime(true) * 1000);
        $id = $this->createGenerator()->generate()->value;
        $after = (int) floor(microtime(true) * 1000);

        $milliseconds = (int) hexdec(substr(str_replace('-', '', $id), 0, 12));

        self::assertGreaterThanOrEqual($before, $milliseconds);
        self::assertLessThanOrEqual($after, $milliseconds);
    }
}
