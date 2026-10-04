<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

use DateMalformedStringException;

/**
 * Дата рождения (без времени, UTC). Формат строки: YYYY-MM-DD.
 * Правило «не в будущем» зависит от текущего времени и проверяется при регистрации (User::register).
 */
final readonly class BirthDate
{
    private const string FORMAT = 'Y-m-d';
    private const string EARLIEST = '1900-01-01';

    private function __construct(public \DateTimeImmutable $value)
    {
    }

    /**
     * @throws DateMalformedStringException
     */
    public static function fromString(string $value): self
    {
        $date = \DateTimeImmutable::createFromFormat('!' . self::FORMAT, $value, new \DateTimeZone('UTC'));

        // Сравнение с исходной строкой отсекает «перекатывающиеся» даты вроде 2020-02-30.
        if ($date === false || $date->format(self::FORMAT) !== $value) {
            throw InvalidUserData::forField('birthDate', 'birthDate must be a valid date in YYYY-MM-DD format.');
        }

        if ($date < new \DateTimeImmutable(self::EARLIEST, new \DateTimeZone('UTC'))) {
            throw InvalidUserData::forField('birthDate', sprintf('birthDate must not be earlier than %s.', self::EARLIEST));
        }

        return new self($date);
    }

    public function isAfter(\DateTimeImmutable $moment): bool
    {
        $today = $moment->setTimezone(new \DateTimeZone('UTC'))->setTime(0, 0);

        return $this->value > $today;
    }

    public function toString(): string
    {
        return $this->value->format(self::FORMAT);
    }
}
