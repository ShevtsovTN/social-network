<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\Post\PostId;

interface PostIdGenerator
{
    /**
     * Новый уникальный идентификатор (UUID, лучше UUIDv7: упорядочен по времени). Известен до записи в БД.
     */
    public function generate(): PostId;
}
