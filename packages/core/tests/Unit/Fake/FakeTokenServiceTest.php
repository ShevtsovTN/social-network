<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\TokenIssuer;
use SocialNetwork\Core\Application\Port\TokenVerifier;
use SocialNetwork\CoreContract\TokenContractTest;

final class FakeTokenServiceTest extends TokenContractTest
{
    protected function createIssuer(): TokenIssuer
    {
        return new FakeTokenService('test-secret');
    }

    protected function createVerifier(): TokenVerifier
    {
        return new FakeTokenService('test-secret');
    }

    protected function createVerifierWithDifferentSecret(): TokenVerifier
    {
        return new FakeTokenService('another-secret');
    }

    protected function createIssuerWithEmptySecret(): TokenIssuer
    {
        return new FakeTokenService('');
    }
}
