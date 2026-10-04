<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Response;

use JsonException;

final readonly class JsonResponse
{
    /**
     * @param array<string, mixed> $body
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {
    }

    /**
     * @throws JsonException
     */
    public function send(): void
    {
        http_response_code($this->status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
