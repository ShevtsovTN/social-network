<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\User\NamePrefix;
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

    /**
     * Пользователи, у которых имя начинается с $firstName и одновременно фамилия начинается с $lastName.
     * Сравнение регистрозависимое и буквальное: символы префикса не являются шаблонами. Отсортированы по id.
     *
     * @return list<User>
     */
    public function searchByNamePrefix(NamePrefix $firstName, NamePrefix $lastName): array;
}
