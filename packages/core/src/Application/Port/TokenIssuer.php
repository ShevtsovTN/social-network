<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\User\UserId;

interface TokenIssuer
{
    /**
     * Выпускает токен доступа для пользователя. Формат токена определяет реализация.
     */
    public function issue(UserId $userId): string;
}
