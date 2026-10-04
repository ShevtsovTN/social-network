<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Request;

/**
 * Разобранное JSON-тело запроса. Проверяет только тип поля (формат запроса);
 * содержательная валидация значений остаётся за доменом.
 */
final readonly class JsonBody
{
    /**
     * @param array<array-key, mixed> $data
     */
    public function __construct(private array $data)
    {
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
