<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\Port;

use SocialNetwork\Core\Domain\User\PasswordHash;
use SocialNetwork\Core\Domain\User\PlainPassword;

interface PasswordHasher
{
    /**
     * Необратимое хеширование с солью: два вызова для одного пароля дают разные хеши.
     */
    public function hash(PlainPassword $password): PasswordHash;

    /**
     * Не бросает исключений на «чужом» или повреждённом хеше, а возвращает false.
     */
    public function verify(PlainPassword $password, PasswordHash $hash): bool;
}
