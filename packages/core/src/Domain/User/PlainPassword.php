<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\User;

/**
 * Пароль в открытом виде. Живёт только на время запроса, нигде не хранится и не логируется.
 *
 * Две точки входа, потому что требования разные:
 *  - createNew: новый пароль при регистрации, действует политика сложности;
 *  - fromInput: ввод при логине, политику не применяем (не раскрываем её и не отсекаем старые пароли).
 */
final readonly class PlainPassword
{
    private const int MIN_LENGTH = 8;
    private const int MAX_LENGTH = 128;

    private string $value;

    private function __construct(#[\SensitiveParameter] string $value)
    {
        $this->value = $value;
    }

    public static function createNew(#[\SensitiveParameter] string $value): self
    {
        self::assertLength($value, self::MIN_LENGTH);

        return new self($value);
    }

    public static function fromInput(#[\SensitiveParameter] string $value): self
    {
        self::assertLength($value, 1);

        return new self($value);
    }

    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }

    private static function assertLength(string $value, int $min): void
    {
        $length = mb_strlen($value);

        if ($length < $min) {
            throw InvalidUserData::forField('password', sprintf('password must be at least %d characters long.', $min));
        }

        if ($length > self::MAX_LENGTH) {
            throw InvalidUserData::forField('password', sprintf('password must be at most %d characters long.', self::MAX_LENGTH));
        }
    }
}
