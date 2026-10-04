<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Http\Request;

use JsonException;
use Symfony\Component\HttpFoundation\Request;

/**
 * Разобранное JSON-тело запроса. Проверяет только тип поля (формат запроса);
 * содержательная валидация значений остаётся за доменом.
 */
final readonly class JsonBody
{
    /**
     * @param array<array-key, mixed> $data
     */
    private function __construct(private array $data)
    {
    }

    /**
     * @throws MalformedRequest если тело не является валидным JSON
     */
    public static function fromRequest(Request $request): self
    {
        $rawBody = $request->getContent();

        if ($rawBody === '') {
            return new self([]);
        }

        try {
            $decoded = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw MalformedRequest::forField('body', 'Request body must be valid JSON.');
        }

        return new self(is_array($decoded) ? $decoded : []);
    }

    /**
     * @throws MalformedRequest
     */
    public function string(string $field): string
    {
        $value = $this->data[$field] ?? null;

        if (!is_string($value)) {
            throw MalformedRequest::forField($field, sprintf('%s is required and must be a string.', $field));
        }

        return $value;
    }

    /**
     * @return list<string>
     * @throws MalformedRequest
     */
    public function stringList(string $field): array
    {
        $value = $this->data[$field] ?? null;

        if (!is_array($value) || !array_is_list($value)) {
            throw MalformedRequest::forField($field, sprintf('%s is required and must be an array of strings.', $field));
        }

        foreach ($value as $item) {
            if (!is_string($item)) {
                throw MalformedRequest::forField($field, sprintf('%s is required and must be an array of strings.', $field));
            }
        }

        return $value;
    }
}
