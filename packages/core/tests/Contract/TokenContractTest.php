<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Port\TokenIssuer;
use SocialNetwork\Core\Application\Port\TokenVerifier;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Контракт портов TokenIssuer и TokenVerifier. Issuer и verifier из createIssuer()/createVerifier()
 * должны быть настроены на один и тот же секрет.
 */
abstract class TokenContractTest extends TestCase
{
    abstract protected function createIssuer(): TokenIssuer;

    abstract protected function createVerifier(): TokenVerifier;

    /** Verifier с другим секретом: токены issuer'а он принимать не должен. */
    abstract protected function createVerifierWithDifferentSecret(): TokenVerifier;

    /** Создание сервиса с пустым секретом: токены с пустым ключом подделывает кто угодно. */
    abstract protected function createIssuerWithEmptySecret(): TokenIssuer;

    public function testVerifiedTokenReturnsItsOwner(): void
    {
        $userId = UserId::fromString(UserFixture::DEFAULT_ID);

        $token = $this->createIssuer()->issue($userId);

        $owner = $this->createVerifier()->verify($token);
        self::assertNotNull($owner);
        self::assertTrue($owner->equals($userId));
    }

    public function testTokenBelongsToExactlyOneUser(): void
    {
        $first = UserId::fromString(UserFixture::DEFAULT_ID);
        $second = UserId::fromString(UserFixture::OTHER_ID);
        $issuer = $this->createIssuer();
        $verifier = $this->createVerifier();

        self::assertFalse($verifier->verify($issuer->issue($first))?->equals($second));
        self::assertTrue($verifier->verify($issuer->issue($second))?->equals($second));
    }

    public function testRejectsGarbage(): void
    {
        $verifier = $this->createVerifier();

        foreach (['', ' ', 'abc', 'a.b.c', '...', 'Bearer', str_repeat('x', 5000)] as $garbage) {
            self::assertNull($verifier->verify($garbage), sprintf('Garbage token %s was accepted.', substr($garbage, 0, 20)));
        }
    }

    public function testRejectsTamperedToken(): void
    {
        $token = $this->createIssuer()->issue(UserId::fromString(UserFixture::DEFAULT_ID));
        $lastCharacter = substr($token, -1);
        $tampered = substr($token, 0, -1) . ($lastCharacter === 'a' ? 'b' : 'a');

        self::assertNull($this->createVerifier()->verify($tampered));
    }

    public function testRejectsTokenSignedWithDifferentSecret(): void
    {
        $token = $this->createIssuer()->issue(UserId::fromString(UserFixture::DEFAULT_ID));

        self::assertNull($this->createVerifierWithDifferentSecret()->verify($token));
    }

    public function testRefusesEmptySecret(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createIssuerWithEmptySecret();
    }
}
