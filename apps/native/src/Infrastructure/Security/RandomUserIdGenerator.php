<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Infrastructure\Security;

use Random\RandomException;
use SocialNetwork\Core\Application\Port\UserIdGenerator;
use SocialNetwork\Core\Domain\User\UserId;

final class RandomUserIdGenerator implements UserIdGenerator
{
    /**
     * @throws RandomException
     */
    public function generate(): UserId
    {
        $bytes = random_bytes(16);

        // Версия 4, вариант RFC 4122 — только чтобы id выглядел как «обычный» UUID.
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);
        $uuid = sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        );

        return UserId::fromString($uuid);
    }
}
