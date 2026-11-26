<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Authenticate;

/**
 * Единая ошибка для всех причин отказа: нет токена, неверный формат, подпись, срок действия.
 */
final class Unauthenticated extends \RuntimeException
{
    public static function create(): self
    {
        return new self('Authentication token is missing or invalid.');
    }
}
