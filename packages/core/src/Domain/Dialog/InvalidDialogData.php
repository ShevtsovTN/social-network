<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Dialog;

use SocialNetwork\Core\Domain\DomainException;

/**
 * Нарушен инвариант диалога или сообщения. Поле называется в терминах домена
 * (recipientId, companionId, text, limit, before, id), приложения сами сопоставляют его с полями запроса.
 */
final class InvalidDialogData extends DomainException
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
