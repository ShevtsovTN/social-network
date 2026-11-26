<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Dialog;

use SocialNetwork\Core\Domain\User\UserId;

/**
 * Идентификатор диалога двух пользователей. Не хранится отдельно, а вычисляется по паре участников,
 * поэтому одинаков для обоих направлений переписки.
 *
 * По нему сообщения раскладываются по шардам, так что способ вычисления менять нельзя: уже записанные
 * сообщения перестали бы находиться. Способ: первые 16 байт SHA-256 от строки «меньший id:больший id»
 * (id пользователей в нижнем регистре) с битами версии 8 и варианта UUID (RFC 9562).
 */
final readonly class DialogId
{
    private function __construct(public string $value)
    {
    }

    /**
     * @param string $field поле для ошибки: чьим идентификатором оказался id самого пользователя
     *
     * @throws InvalidDialogData если участники совпадают
     */
    public static function between(UserId $first, UserId $second, string $field = 'companionId'): self
    {
        if ($first->equals($second)) {
            throw InvalidDialogData::forField($field, 'You cannot have a dialog with yourself.');
        }

        $pair = [$first->value, $second->value];
        sort($pair, SORT_STRING);

        $hex = substr(hash('sha256', implode(':', $pair)), 0, 32);
        $hex[12] = '8';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return new self(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
