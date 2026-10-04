<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\User\UserId;

interface TokenVerifier
{
    /**
     * Возвращает владельца токена или null, если токен неверный, подделан или просрочен.
     * Никогда не бросает исключений на мусорном вводе.
     */
    public function verify(string $token): ?UserId;
}
