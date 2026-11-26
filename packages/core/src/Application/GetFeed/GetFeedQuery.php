<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetFeed;

use SocialNetwork\Core\Domain\User\UserId;

final readonly class GetFeedQuery
{
    /**
     * @param string|null $offset значение параметра запроса как есть; null, если параметра нет (по умолчанию 0)
     * @param string|null $limit значение параметра запроса как есть; null, если параметра нет (по умолчанию 10)
     */
    public function __construct(public UserId $readerId, public ?string $offset = null, public ?string $limit = null)
    {
    }
}
