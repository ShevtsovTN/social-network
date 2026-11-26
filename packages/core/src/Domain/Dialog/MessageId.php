<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Dialog;

/**
 * Идентификатор сообщения (UUID). Порядок id совпадает с порядком сообщений в истории диалога,
 * поэтому он же служит курсором пагинации.
 */
final readonly class MessageId
{
    private function __construct(public string $value)
    {
    }

    /**
     * @param string $field поле для ошибки: «id» по умолчанию, курсор пагинации передаёт «before»
     *
     * @throws InvalidDialogData
     */
    public static function fromString(string $value, string $field = 'id'): self
    {
        $normalized = strtolower($value);

        // Флаг D: без него «$» пропускает завершающий перевод строки.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $normalized) !== 1) {
            throw InvalidDialogData::forField(
                $field,
                $field === 'id' ? 'Message id must be a valid UUID.' : sprintf('%s must be a valid UUID.', $field),
            );
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
