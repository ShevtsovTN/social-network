<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Response;

use SocialNetwork\Core\Domain\User\User;

/**
 * Анкета в виде схемы User из docs/openapi.json. Хеш пароля не попадает в ответ.
 * Чистая функция без состояния, создавать экземпляры не нужно.
 */
final class UserPayload
{
    private function __construct()
    {
    }

    /**
     * @return array<string, mixed>
     */
    public static function fromUser(User $user): array
    {
        return [
            'id' => $user->id->value,
            'firstName' => $user->firstName->value,
            'lastName' => $user->lastName->value,
            'birthDate' => $user->birthDate->toString(),
            'gender' => $user->gender->value,
            'interests' => $user->interests->values,
            'city' => $user->city->value,
        ];
    }
}
