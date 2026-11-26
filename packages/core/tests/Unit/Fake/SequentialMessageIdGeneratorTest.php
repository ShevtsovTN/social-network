<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\MessageIdGenerator;
use SocialNetwork\CoreContract\MessageIdGeneratorContractTest;

/**
 * Фейк тоже обязан соблюдать контракт порта, иначе unit-тесты use case'ов проверяют не то.
 */
final class SequentialMessageIdGeneratorTest extends MessageIdGeneratorContractTest
{
    protected function createGenerator(): MessageIdGenerator
    {
        return new SequentialMessageIdGenerator();
    }
}
