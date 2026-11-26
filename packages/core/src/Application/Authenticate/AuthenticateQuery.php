<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Authenticate;

final readonly class AuthenticateQuery
{
    /**
     * @param string $token токен без префикса «Bearer »; пустая строка, если заголовка нет
     */
    public function __construct(
        #[\SensitiveParameter]
        public string $token,
    ) {
    }
}
