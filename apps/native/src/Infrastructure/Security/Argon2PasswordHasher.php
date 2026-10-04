<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Infrastructure\Security;

use SocialNetwork\Core\Application\Port\PasswordHasher;
use SocialNetwork\Core\Domain\User\PasswordHash;
use SocialNetwork\Core\Domain\User\PlainPassword;

/**
 * Параметры зафиксированы явно (а не оставлены на дефолты PHP), чтобы
 * Symfony-версия могла задать точно такие же значения в своей конфигурации.
 */
final class Argon2PasswordHasher implements PasswordHasher
{
    private const int MEMORY_COST = 65536;
    private const int TIME_COST = 4;
    private const int THREADS = 1;

    public function hash(PlainPassword $password): PasswordHash
    {
        $hash = password_hash($password->reveal(), PASSWORD_ARGON2ID, [
            'memory_cost' => self::MEMORY_COST,
            'time_cost' => self::TIME_COST,
            'threads' => self::THREADS,
        ]);

        return PasswordHash::fromString($hash);
    }

    public function verify(PlainPassword $password, PasswordHash $hash): bool
    {
        return password_verify($password->reveal(), $hash->value);
    }
}
