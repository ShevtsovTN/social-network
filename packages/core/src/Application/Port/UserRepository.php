<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\User\User;
use SocialNetwork\Core\Domain\User\UserId;

interface UserRepository
{
    /**
     * Сохраняет нового пользователя. Только вставка: обновление существующего не поддерживается.
     * Повторное сохранение пользователя с тем же id нарушает уникальность и завершается исключением инфраструктуры.
     */
    public function save(User $user): void;

    public function findById(UserId $id): ?User;
}
