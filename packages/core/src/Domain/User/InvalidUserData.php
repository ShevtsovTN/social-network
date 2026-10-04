<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

use SocialNetwork\Core\Domain\DomainException;

/**
 * Нарушен инвариант данных пользователя. Поле называется в терминах домена
 * (firstName, birthDate, ...), приложения сами сопоставляют его с полями запроса.
 */
final class InvalidUserData extends DomainException
{
    private function __construct(public readonly string $field, string $message)
    {
        parent::__construct($message);
    }

    public static function forField(string $field, string $message): self
    {
        return new self($field, $message);
    }
}
