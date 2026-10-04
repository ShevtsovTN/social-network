<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Security;

use InvalidArgumentException;
use SocialNetwork\Core\Application\Port\TokenIssuer;
use SocialNetwork\Core\Application\Port\TokenVerifier;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Токен: v1.<userId>.<hmac-sha256>. Без состояния и срока действия —
 * отзыв токенов не входит в задание (нет кэша/дополнительных таблиц).
 */
final readonly class HmacTokenService implements TokenIssuer, TokenVerifier
{
    private const string VERSION = 'v1';

    public function __construct(private string $secret)
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Token secret must not be empty.');
        }
    }

    public function issue(UserId $userId): string
    {
        return self::VERSION . '.' . $userId->value . '.' . $this->sign($userId->value);
    }

    public function verify(string $token): ?UserId
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $parts[0] !== self::VERSION) {
            return null;
        }

        [, $rawUserId, $signature] = $parts;

        if (!hash_equals($this->sign($rawUserId), $signature)) {
            return null;
        }

        try {
            return UserId::fromString($rawUserId);
        } catch (InvalidUserData) {
            return null;
        }
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
