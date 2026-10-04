<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Domain;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Domain\User\PlainPassword;
use SocialNetwork\Core\Tests\Unit\Support\AssertsInvalidUserData;

final class PlainPasswordTest extends TestCase
{
    use AssertsInvalidUserData;

    public function testNewPasswordMustFollowPolicy(): void
    {
        $this->assertInvalidField('password', static fn () => PlainPassword::createNew('short'));
        $this->assertInvalidField('password', static fn () => PlainPassword::createNew(str_repeat('a', 129)));

        self::assertSame('12345678', PlainPassword::createNew('12345678')->reveal());
    }

    public function testLengthIsCountedInCharactersNotBytes(): void
    {
        // 8 символов, но 16 байт.
        self::assertSame('пароль-12', PlainPassword::createNew('пароль-12')->reveal());
        $this->assertInvalidField('password', static fn () => PlainPassword::createNew('пароль1'));
    }

    public function testLoginInputIsNotSubjectToPolicy(): void
    {
        self::assertSame('abc', PlainPassword::fromInput('abc')->reveal());
    }

    public function testLoginInputMustNotBeEmptyOrHuge(): void
    {
        $this->assertInvalidField('password', static fn () => PlainPassword::fromInput(''));
        $this->assertInvalidField('password', static fn () => PlainPassword::fromInput(str_repeat('a', 129)));
    }

    public function testDebugOutputDoesNotLeakPassword(): void
    {
        $password = PlainPassword::createNew('very-secret-value');

        ob_start();
        var_dump($password);
        $dump = (string) ob_get_clean();

        self::assertStringNotContainsString('very-secret-value', $dump);
    }
}
