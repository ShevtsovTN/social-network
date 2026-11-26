<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Dialog;

/**
 * Страница диалога для подгрузки истории при прокрутке: не больше limit сообщений (от 1 до 100, по умолчанию 50),
 * идущих в истории раньше сообщения before. Без before — самые новые сообщения.
 *
 * Курсор вместо offset: новые сообщения не сдвигают уже загруженные страницы, а глубина прокрутки
 * не меняет стоимость запроса.
 */
final readonly class DialogPageRequest
{
    public const int DEFAULT_SIZE = 50;

    public const int MAX_SIZE = 100;

    private function __construct(public int $limit, public ?MessageId $before)
    {
    }

    /**
     * @param string|null $limit значение параметра запроса как есть; null, если параметра нет
     * @param string|null $before значение параметра запроса как есть; null, если параметра нет
     *
     * @throws InvalidDialogData (field = limit или before)
     */
    public static function fromInput(?string $limit, ?string $before): self
    {
        return new self(
            self::parseLimit($limit),
            $before === null ? null : MessageId::fromString($before, 'before'),
        );
    }

    private static function parseLimit(?string $raw): int
    {
        if ($raw === null) {
            return self::DEFAULT_SIZE;
        }

        // Слишком длинная строка цифр при приведении к int даёт PHP_INT_MAX и отсекается верхней границей.
        $limit = preg_match('/^[0-9]+$/D', $raw) === 1 ? (int) $raw : 0;

        if ($limit < 1 || $limit > self::MAX_SIZE) {
            throw InvalidDialogData::forField('limit', sprintf('limit must be an integer between 1 and %d.', self::MAX_SIZE));
        }

        return $limit;
    }
}
