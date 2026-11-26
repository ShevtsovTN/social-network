<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Request;

use JsonException;

final readonly class Request
{
    /**
     * @param array<array-key, mixed> $queryParameters
     */
    private function __construct(
        public string $method,
        public string $path,
        private string $rawBody,
        private array $queryParameters,
        private string $authorization,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = (string) (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?? '/');

        // php-fpm передаёт заголовок Authorization как HTTP_AUTHORIZATION; REDIRECT_ вариант встречается за внутренним перенаправлением.
        $authorization = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');

        return new self($method, $path, (string) file_get_contents('php://input'), $_GET, $authorization);
    }

    /**
     * @param array<array-key, mixed> $queryParameters
     */
    public static function fromParts(
        string $method,
        string $path,
        string $rawBody,
        array $queryParameters = [],
        string $authorization = '',
    ): self {
        return new self($method, $path, $rawBody, $queryParameters, $authorization);
    }

    /**
     * Токен из заголовка «Authorization: Bearer <token>» без префикса. Пустая строка, если заголовка нет
     * или схема не Bearer: решение «пускать или нет» принимает use case Authenticate, а не разбор заголовка.
     */
    public function bearerToken(): string
    {
        return preg_match('/^Bearer ([^\s]+)$/iD', $this->authorization, $matches) === 1 ? $matches[1] : '';
    }

    public function query(): QueryParameters
    {
        return new QueryParameters($this->queryParameters);
    }

    /**
     * @throws MalformedRequest если тело не является валидным JSON
     */
    public function jsonBody(): JsonBody
    {
        if ($this->rawBody === '') {
            return new JsonBody([]);
        }

        try {
            $decoded = json_decode($this->rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MalformedRequest::forField('body', 'Request body must be valid JSON.');
        }

        return new JsonBody(is_array($decoded) ? $decoded : []);
    }
}
