<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Request;

use RuntimeException;

/**
 * Запрос не того формата, чтобы дойти до use case (отсутствует или неверного
 * типа обязательное поле). Отдельно от доменного InvalidUserData: там —
 * нарушение бизнес-инварианта, здесь — запрос, который даже не стоит пытаться обработать.
 */
final class MalformedRequest extends RuntimeException
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
