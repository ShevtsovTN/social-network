<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\Dialog\MessageId;

interface MessageIdGenerator
{
    /**
     * Новый уникальный идентификатор, известный до записи в БД. Упорядочен по времени (UUIDv7): id, созданный
     * позже хотя бы на миллисекунду, больше созданного раньше. На этом порядке держатся история диалога
     * и курсор пагинации; порядок id, созданных в пределах одной миллисекунды, не определён.
     */
    public function generate(): MessageId;
}
