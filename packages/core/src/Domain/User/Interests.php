<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

/**
 * Список интересов: без пустых значений и дубликатов (без учёта регистра), порядок сохраняется.
 * Способ хранения (строка, JSON, отдельная таблица) выбирает Infrastructure.
 */
final readonly class Interests
{
    private const int MAX_COUNT = 20;
    private const int MAX_LENGTH = 50;

    /**
     * @param list<string> $values
     */
    private function __construct(public array $values)
    {
    }

    /**
     * @param list<string> $values
     */
    public static function fromList(array $values): self
    {
        $unique = [];

        foreach ($values as $value) {
            if (trim($value) === '') {
                continue;
            }

            $interest = RequiredText::normalize($value, 'interests', self::MAX_LENGTH);
            $unique[mb_strtolower($interest)] ??= $interest;
        }

        if (count($unique) > self::MAX_COUNT) {
            throw InvalidUserData::forField('interests', sprintf('interests must contain at most %d items.', self::MAX_COUNT));
        }

        return new self(array_values($unique));
    }

    public static function none(): self
    {
        return new self([]);
    }

    public function isEmpty(): bool
    {
        return $this->values === [];
    }
}
