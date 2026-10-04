<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Port\PasswordHasher;
use SocialNetwork\Core\Domain\User\PasswordHash;
use SocialNetwork\Core\Domain\User\PlainPassword;

/**
 * Контракт порта PasswordHasher.
 */
abstract class PasswordHasherContractTest extends TestCase
{
    abstract protected function createHasher(): PasswordHasher;

    public function testVerifiesCorrectPassword(): void
    {
        $hasher = $this->createHasher();
        $password = PlainPassword::createNew('correct horse battery');

        self::assertTrue($hasher->verify($password, $hasher->hash($password)));
    }

    public function testRejectsWrongPassword(): void
    {
        $hasher = $this->createHasher();
        $hash = $hasher->hash(PlainPassword::createNew('correct horse battery'));

        self::assertFalse($hasher->verify(PlainPassword::createNew('wrong horse battery'), $hash));
    }

    public function testHashDoesNotContainPlainPassword(): void
    {
        $hasher = $this->createHasher();
        $hash = $hasher->hash(PlainPassword::createNew('correct horse battery'));

        self::assertStringNotContainsString('correct horse battery', $hash->value);
    }

    public function testHashIsSalted(): void
    {
        $hasher = $this->createHasher();
        $password = PlainPassword::createNew('correct horse battery');

        $first = $hasher->hash($password);
        $second = $hasher->hash($password);

        self::assertNotSame($first->value, $second->value);
        self::assertTrue($hasher->verify($password, $first));
        self::assertTrue($hasher->verify($password, $second));
    }

    public function testReturnsFalseForForeignOrBrokenHash(): void
    {
        $hasher = $this->createHasher();
        $password = PlainPassword::createNew('correct horse battery');

        self::assertFalse($hasher->verify($password, PasswordHash::fromString('not-a-valid-hash')));
        self::assertFalse($hasher->verify($password, PasswordHash::fromString('$2y$10$abcdefghijklmnopqrstuv')));
    }

    public function testHandlesUnicodePasswords(): void
    {
        $hasher = $this->createHasher();
        $password = PlainPassword::createNew('пароль-с-юникодом-🔒');

        self::assertTrue($hasher->verify($password, $hasher->hash($password)));
    }
}
