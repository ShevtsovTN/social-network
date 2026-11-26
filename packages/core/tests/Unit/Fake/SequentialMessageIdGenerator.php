<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\MessageIdGenerator;
use SocialNetwork\Core\Domain\Dialog\MessageId;

/**
 * Возрастающие id: миллисекунды (как в UUIDv7), затем счётчик экземпляра. Время нужно, чтобы порядок сохранялся
 * и между экземплярами, как требует контракт порта.
 */
final class SequentialMessageIdGenerator implements MessageIdGenerator
{
    private int $counter = 0;

    public function generate(): MessageId
    {
        $milliseconds = sprintf('%012x', (int) (microtime(true) * 1000));

        return MessageId::fromString(sprintf(
            '%s-%s-7000-8000-%012d',
            substr($milliseconds, 0, 8),
            substr($milliseconds, 8, 4),
            ++$this->counter,
        ));
    }
}
