<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Login;

interface Login
{
    /**
     * @return string токен доступа
     *
     * @throws InvalidCredentials
     */
    public function handle(LoginCommand $command): string;
}
