<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

/**
 * Результат хеширования пароля. Формат определяет реализация PasswordHasher (в приложениях).
 */
final readonly class PasswordHash
{
    private const int MAX_LENGTH = 255;

    private function __construct(public string $value)
    {
    }

    public static function fromString(string $value): self
    {
        if ($value === '' || strlen($value) > self::MAX_LENGTH) {
            throw InvalidUserData::forField('password', 'Password hash must be between 1 and 255 bytes long.');
        }

        return new self($value);
    }
}
