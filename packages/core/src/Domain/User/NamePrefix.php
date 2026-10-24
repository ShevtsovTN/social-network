<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

/**
 * Начало имени или фамилии для поиска. Отдельно от Name: «Ив» — допустимый префикс, но не имя.
 * Все символы, включая % и _, означают сами себя; экранирование под конкретное хранилище — дело репозитория.
 */
final readonly class NamePrefix
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
