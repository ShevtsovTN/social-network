<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Response;

use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * JSON-ответ в том же виде, что и в apps/native: юникод без экранирования,
 * Content-Type с charset.
 */
final class ApiJsonResponse extends JsonResponse
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(int $status, array $body)
    {
        $this->encodingOptions = JSON_UNESCAPED_UNICODE;

        parent::__construct($body, $status, ['Content-Type' => 'application/json; charset=utf-8']);
    }
}
