<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Persistence;

use Closure;
use PDOException;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\User\NamePrefix;
use SocialNetwork\Core\Domain\User\User;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Чтение с реплики с возвратом к primary, если реплика анкету не нашла.
 *
 * Зачем: репликация асинхронна, и анкета, созданная долей секунды назад, на реплике может ещё не появиться.
 * Без возврата клиент, который сразу после регистрации запрашивает анкету, получил бы ложный 404.
 * Цена: настоящий промах (id не существует) тоже доходит до primary, поэтому для get-запросов с несуществующими id
 * нагрузка на primary не нулевая. Поиск по префиксу так не проверить (нельзя понять, чего не хватает), он читает только реплику.
 *
 * Primary подключается лениво, через фабрику: пока реплика отвечает, соединения с primary нет, и чтение
 * не зависит от его доступности. Если primary недоступен, промах реплики считается «не найдено»:
 * лучше ответить 404, чем уронить чтение, которое реплика обслуживает штатно.
 *
 * Запись всегда идёт в primary.
 */
final readonly class PrimaryFallbackUserRepository implements UserRepository
{
    /**
     * @param Closure(): UserRepository $primary
     */
    public function __construct(
        private UserRepository $replica,
        private Closure $primary,
    ) {
    }

    public function save(User $user): void
    {
        ($this->primary)()->save($user);
    }

    public function findById(UserId $id): ?User
    {
        $user = $this->replica->findById($id);

        if ($user !== null) {
            return $user;
        }

        try {
            return ($this->primary)()->findById($id);
        } catch (PDOException) {
            return null;
        }
    }

    public function searchByNamePrefix(NamePrefix $firstName, NamePrefix $lastName): array
    {
        return $this->replica->searchByNamePrefix($firstName, $lastName);
    }
}
