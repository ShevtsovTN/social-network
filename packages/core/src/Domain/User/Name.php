<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

/**
 * Имя или фамилия. Поле передаётся, чтобы ошибка указывала на конкретное поле.
 */
final readonly class Name
{
    private const int MAX_LENGTH = 100;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value, string $field): self
    {
        return new self(RequiredText::normalize($value, $field, self::MAX_LENGTH));
    }
}
