<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Feed;

/**
 * Страница ленты: offset с нуля и limit от 1 до 100. Отсутствующие параметры (null) берутся по умолчанию (0 и 10).
 * offset за границей ленты не ошибка (страница пуста), поэтому огромный offset приводится к вместимости ленты.
 */
final readonly class FeedPageRequest
{
    private function __construct(public int $offset, public int $limit)
    {
    }

    public static function fromInput(?string $offset, ?string $limit): self
    {
        return new self(self::parseOffset($offset), self::parseLimit($limit));
    }

    private static function parseOffset(?string $raw): int
    {
        if ($raw === null) {
            return 0;
        }

        if (preg_match('/^[0-9]+$/D', $raw) !== 1) {
            throw InvalidFeedPage::forField('offset', 'offset must be a non-negative integer.');
        }

        return min(self::toInt($raw), FeedLimits::CAPACITY);
    }

    private static function parseLimit(?string $raw): int
    {
        if ($raw === null) {
            return FeedLimits::DEFAULT_PAGE_SIZE;
        }

        $message = sprintf('limit must be an integer between 1 and %d.', FeedLimits::MAX_PAGE_SIZE);

        if (preg_match('/^[0-9]+$/D', $raw) !== 1) {
            throw InvalidFeedPage::forField('limit', $message);
        }

        $limit = self::toInt($raw);

        if ($limit < 1 || $limit > FeedLimits::MAX_PAGE_SIZE) {
            throw InvalidFeedPage::forField('limit', $message);
        }

        return $limit;
    }

    private static function toInt(string $digits): int
    {
        // Слишком длинные числа не помещаются в int: для границ ленты они всё равно «очень большие».
        return strlen($digits) > 18 ? PHP_INT_MAX : (int) $digits;
    }
}
