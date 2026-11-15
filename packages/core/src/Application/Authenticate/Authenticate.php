<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Authenticate;

use SocialNetwork\Core\Domain\User\UserId;

interface Authenticate
{
    /**
     * @return UserId владелец токена
     *
     * @throws Unauthenticated токен отсутствует, неверный, подделан или просрочен
     */
    public function handle(AuthenticateQuery $query): UserId;
}
