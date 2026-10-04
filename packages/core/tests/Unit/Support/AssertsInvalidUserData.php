<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Support;

use SocialNetwork\Core\Domain\User\InvalidUserData;

trait AssertsInvalidUserData
{
    /**
     * Действие должно бросить InvalidUserData именно для указанного поля.
     */
    private function assertInvalidField(string $field, callable $action): void
    {
        try {
            $action();
        } catch (InvalidUserData $exception) {
            self::assertSame($field, $exception->field);

            return;
        }

        self::fail(sprintf('Expected InvalidUserData for field "%s", nothing was thrown.', $field));
    }
}
