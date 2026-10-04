<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\PasswordHasher;
use SocialNetwork\CoreContract\PasswordHasherContractTest;

final class FakePasswordHasherTest extends PasswordHasherContractTest
{
    protected function createHasher(): PasswordHasher
    {
        return new FakePasswordHasher();
    }
}
