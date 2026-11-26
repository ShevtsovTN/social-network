<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Port\MessageIdGenerator;

/**
 * Контракт порта MessageIdGenerator: уникальность и порядок по времени. Каждая реализация
 * наследует этот класс и возвращает свой генератор из createGenerator().
 */
abstract class MessageIdGeneratorContractTest extends TestCase
{
    abstract protected function createGenerator(): MessageIdGenerator;

    public function testGeneratesUniqueIds(): void
    {
        $generator = $this->createGenerator();
        $ids = [];

        for ($i = 0; $i < 1000; $i++) {
            $ids[] = $generator->generate()->value;
        }

        self::assertCount(1000, array_unique($ids));
    }

    public function testIdsCreatedLaterAreGreater(): void
    {
        $generator = $this->createGenerator();
        $ids = [];

        for ($i = 0; $i < 10; $i++) {
            $ids[] = $generator->generate()->value;
            usleep(2000);
        }

        $sorted = $ids;
        sort($sorted, SORT_STRING);

        self::assertSame($sorted, $ids);
    }

    public function testOrderHoldsAcrossGeneratorInstances(): void
    {
        // В php-fpm каждый запрос может получить свой экземпляр генератора: порядок не должен зависеть от его состояния.
        $earlier = $this->createGenerator()->generate()->value;
        usleep(2000);
        $later = $this->createGenerator()->generate()->value;

        self::assertGreaterThan(0, strcmp($later, $earlier));
    }
}
