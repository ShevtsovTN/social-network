<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Login;

/**
 * Единая ошибка для всех причин неудачного входа: неверный id, неизвестный пользователь,
 * неверный пароль. Различать их снаружи нельзя, чтобы не раскрывать, какие id существуют.
 */
final class InvalidCredentials extends \RuntimeException
{
    public static function create(): self
    {
        return new self('Invalid user id or password.');
    }
}
