<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Request;

use Symfony\Component\HttpFoundation\Request;

/**
 * Токен из заголовка «Authorization: Bearer <token>» без префикса. Пустая строка, если заголовка нет или схема не Bearer:
 * решение «пускать или нет» принимает use case Authenticate, а не разбор заголовка.
 * Чистая функция без состояния, создавать экземпляры не нужно.
 */
final class BearerToken
{
    private function __construct()
    {
    }

    public static function fromRequest(Request $request): string
    {
        $header = (string) $request->headers->get('Authorization', '');

        return preg_match('/^Bearer ([^\s]+)$/iD', $header, $matches) === 1 ? $matches[1] : '';
    }
}
