<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\User\BirthDate;
use SocialNetwork\Core\Tests\Unit\Support\AssertsInvalidUserData;

final class BirthDateTest extends TestCase
{
    use AssertsInvalidUserData;

    public function testParsesValidDate(): void
    {
        self::assertSame('1990-05-17', BirthDate::fromString('1990-05-17')->toString());
    }

    public function testAcceptsLeapDay(): void
    {
        self::assertSame('2000-02-29', BirthDate::fromString('2000-02-29')->toString());
    }

    public function testRejectsMalformedAndImpossibleDates(): void
    {
        foreach (['', 'yesterday', '17.05.1990', '1990-5-17', '1990-13-01', '2021-02-29', '2020-02-30', '1990-05-17 10:00'] as $value) {
            $this->assertInvalidField('birthDate', static fn () => BirthDate::fromString($value));
        }
    }

    public function testRejectsDatesBeforeLowerBound(): void
    {
        $this->assertInvalidField('birthDate', static fn () => BirthDate::fromString('1899-12-31'));
    }

    public function testDetectsDateInTheFuture(): void
    {
        $now = new \DateTimeImmutable('2026-10-03 12:00:00', new \DateTimeZone('Europe/Madrid'));

        self::assertTrue(BirthDate::fromString('2026-10-04')->isAfter($now));
        self::assertFalse(BirthDate::fromString('2026-10-03')->isAfter($now));
        self::assertFalse(BirthDate::fromString('2000-01-01')->isAfter($now));
    }
}
