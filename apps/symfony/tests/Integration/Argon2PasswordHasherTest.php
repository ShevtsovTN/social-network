<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use SocialNetwork\Core\Application\Port\PasswordHasher;
use SocialNetwork\Core\Domain\User\PlainPassword;
use SocialNetwork\CoreContract\PasswordHasherContractTest;
use SocialNetwork\SymfonyApp\Infrastructure\Security\Argon2PasswordHasher;

final class Argon2PasswordHasherTest extends PasswordHasherContractTest
{
    protected function createHasher(): PasswordHasher
    {
        return new Argon2PasswordHasher();
    }

    /** Параметры должны совпадать с Symfony-версией, иначе нагрузочное сравнение нечестное. */
    public function testUsesAgreedArgon2idParameters(): void
    {
        $hash = $this->createHasher()->hash(PlainPassword::createNew('correct horse battery'));

        self::assertStringStartsWith('$argon2id$v=19$m=65536,t=4,p=1$', $hash->value);
    }
}
