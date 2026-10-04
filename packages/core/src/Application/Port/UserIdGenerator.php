<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\User\UserId;

interface UserIdGenerator
{
    /**
     * Новый уникальный идентификатор (UUID). Известен до записи в БД.
     */
    public function generate(): UserId;
}
