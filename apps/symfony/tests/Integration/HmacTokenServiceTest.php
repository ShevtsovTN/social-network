<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Integration;

use SocialNetwork\Core\Application\Port\TokenIssuer;
use SocialNetwork\Core\Application\Port\TokenVerifier;
use SocialNetwork\CoreContract\TokenContractTest;
use SocialNetwork\SymfonyApp\Infrastructure\Security\HmacTokenService;

final class HmacTokenServiceTest extends TokenContractTest
{
    private const string SECRET = 'test-secret';
    private const string OTHER_SECRET = 'another-test-secret';

    protected function createIssuer(): TokenIssuer
    {
        return new HmacTokenService(self::SECRET);
    }

    protected function createVerifier(): TokenVerifier
    {
        return new HmacTokenService(self::SECRET);
    }

    protected function createVerifierWithDifferentSecret(): TokenVerifier
    {
        return new HmacTokenService(self::OTHER_SECRET);
    }

    protected function createIssuerWithEmptySecret(): TokenIssuer
    {
        return new HmacTokenService('');
    }
}
