<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use InvalidArgumentException;
use SocialNetwork\Core\Application\Port\TokenIssuer;
use SocialNetwork\Core\Application\Port\TokenVerifier;
use SocialNetwork\Core\Domain\User\InvalidUserData;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Подписанный токен для тестов: v1.<userId>.<hmac>. Выпуск и проверка в одном классе.
 */
final readonly class FakeTokenService implements TokenIssuer, TokenVerifier
{
    public function __construct(private string $secret)
    {
        if ($secret === '') {
            throw new InvalidArgumentException('Token secret must not be empty.');
        }
    }

    public function issue(UserId $userId): string
    {
        return 'v1.' . $userId->value . '.' . $this->sign($userId->value);
    }

    public function verify(string $token): ?UserId
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3 || $parts[0] !== 'v1') {
            return null;
        }

        if (!hash_equals($this->sign($parts[1]), $parts[2])) {
            return null;
        }

        try {
            return UserId::fromString($parts[1]);
        } catch (InvalidUserData) {
            return null;
        }
    }

    private function sign(string $payload): string
    {
        return hash_hmac('sha256', $payload, $this->secret);
    }
}
