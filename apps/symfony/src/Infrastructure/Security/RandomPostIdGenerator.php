<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Security;

use SocialNetwork\Core\Application\Port\PostIdGenerator;
use SocialNetwork\Core\Domain\Post\PostId;

/**
 * UUIDv7 (RFC 9562): 48 бит Unix-времени в миллисекундах, затем версия, вариант и случайные биты.
 * Упорядочен по времени (с точностью до миллисекунды), поэтому вставки в индекс первичного ключа локальны.
 */
final readonly class RandomPostIdGenerator implements PostIdGenerator
{
    public function generate(): PostId
    {
        $milliseconds = (int) floor(microtime(true) * 1000);
        $timestamp = substr(pack('J', $milliseconds), 2);
        $random = random_bytes(10);
        $random[0] = chr((ord($random[0]) & 0x0F) | 0x70);
        $random[2] = chr((ord($random[2]) & 0x3F) | 0x80);

        $hex = bin2hex($timestamp . $random);

        return PostId::fromString(sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12),
        ));
    }
}
