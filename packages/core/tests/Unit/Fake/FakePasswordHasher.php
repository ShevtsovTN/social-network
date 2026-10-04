<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\PasswordHasher;
use SocialNetwork\Core\Domain\User\PasswordHash;
use SocialNetwork\Core\Domain\User\PlainPassword;

/**
 * Быстрый хешер для тестов: с солью, необратимый, но не для боевого использования.
 */
final class FakePasswordHasher implements PasswordHasher
{
    public int $hashCalls = 0;

    public function hash(PlainPassword $password): PasswordHash
    {
        ++$this->hashCalls;
        $salt = bin2hex(random_bytes(8));

        return PasswordHash::fromString('fake$' . $salt . '$' . hash('sha256', $salt . $password->reveal()));
    }

    public function verify(PlainPassword $password, PasswordHash $hash): bool
    {
        $parts = explode('$', $hash->value);

        if (count($parts) !== 3 || $parts[0] !== 'fake') {
            return false;
        }

        return hash_equals($parts[2], hash('sha256', $parts[1] . $password->reveal()));
    }
}
