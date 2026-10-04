<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

/**
 * Общая нормализация обязательных текстовых полей (имена, город, интересы).
 * Чистая функция без состояния, создавать экземпляры не нужно.
 *
 * @internal
 */
final class RequiredText
{
    private function __construct()
    {
    }

    public static function normalize(string $value, string $field, int $maxLength): string
    {
        if (!mb_check_encoding($value, 'UTF-8')) {
            throw InvalidUserData::forField($field, sprintf('%s must be valid UTF-8.', $field));
        }

        $normalized = trim($value);

        if ($normalized === '') {
            throw InvalidUserData::forField($field, sprintf('%s must not be empty.', $field));
        }

        if (mb_strlen($normalized) > $maxLength) {
            throw InvalidUserData::forField($field, sprintf('%s must be at most %d characters long.', $field, $maxLength));
        }

        if (preg_match('/\p{Cc}/u', $normalized) === 1) {
            throw InvalidUserData::forField($field, sprintf('%s must not contain control characters.', $field));
        }

        return $normalized;
    }
}
