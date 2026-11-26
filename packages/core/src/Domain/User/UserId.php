<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

final readonly class UserId
{
    private function __construct(public string $value)
    {
    }

    /**
     * @param string $field поле для ошибки: «id» по умолчанию, другие use case'ы передают своё
     *                      (например, «friendId» для идентификатора друга)
     */
    public static function fromString(string $value, string $field = 'id'): self
    {
        $normalized = strtolower($value);

        // Флаг D: без него «$» пропускает завершающий перевод строки.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/D', $normalized) !== 1) {
            throw InvalidUserData::forField(
                $field,
                $field === 'id' ? 'User id must be a valid UUID.' : sprintf('%s must be a valid UUID.', $field),
            );
        }

        return new self($normalized);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
