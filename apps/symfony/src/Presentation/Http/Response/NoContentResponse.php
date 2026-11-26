<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Response;

use Symfony\Component\HttpFoundation\Response;

/**
 * Успех без результата: 204 и пустое тело (ApiJsonResponse всегда кодирует массив).
 */
final class NoContentResponse extends Response
{
    public function __construct()
    {
        parent::__construct(null, Response::HTTP_NO_CONTENT);
    }
}
