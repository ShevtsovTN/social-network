<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Response;

use JsonException;

final readonly class JsonResponse
{
    /**
     * @param array<array-key, mixed>|null $body объект или список (например, результат поиска); null — ответ без тела
     */
    public function __construct(
        public int $status,
        public ?array $body,
    ) {
    }

    /**
     * Успех без результата: 204 и пустое тело.
     */
    public static function noContent(): self
    {
        return new self(204, null);
    }

    /**
     * @throws JsonException
     */
    public function send(): void
    {
        http_response_code($this->status);

        if ($this->body === null) {
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($this->body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
