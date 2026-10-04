<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure;

use DateMalformedStringException;
use DateTimeImmutable;
use DateTimeZone;
use SocialNetwork\Core\Application\Port\Clock;

final class SystemClock implements Clock
{
    /**
     * @throws DateMalformedStringException
     */
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }
}
