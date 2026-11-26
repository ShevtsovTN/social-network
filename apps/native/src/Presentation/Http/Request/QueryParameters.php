<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Request;

/**
 * Параметры строки запроса. Проверяет только наличие и тип (формат запроса);
 * содержательная валидация значений остаётся за доменом.
 */
final readonly class QueryParameters
{
    /**
     * @param array<array-key, mixed> $parameters
     */
    public function __construct(private array $parameters)
    {
    }

    /**
     * @param string $field имя поля в терминах домена для ответа об ошибке
     *
     * @throws MalformedRequest
     */
    public function string(string $name, string $field): string
    {
        $value = $this->parameters[$name] ?? null;

        if (!is_string($value)) {
            throw MalformedRequest::forField($field, sprintf('%s is required and must be a string.', $name));
        }

        return $value;
    }

    /**
     * Необязательный параметр: null, если его нет; строка, если он передан строкой.
     *
     * @param string $field имя поля в терминах домена для ответа об ошибке
     *
     * @throws MalformedRequest если параметр передан не строкой (например, offset[]=1)
     */
    public function optionalString(string $name, string $field): ?string
    {
        if (!array_key_exists($name, $this->parameters)) {
            return null;
        }

        $value = $this->parameters[$name];

        if (!is_string($value)) {
            throw MalformedRequest::forField($field, sprintf('%s must be a string.', $name));
        }

        return $value;
    }
}
