<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetDialog;

use SocialNetwork\Core\Domain\User\UserId;

final readonly class GetDialogQuery
{
    /**
     * @param string|null $limit значение параметра запроса как есть; null, если параметра нет (по умолчанию 50)
     * @param string|null $before значение параметра запроса как есть; null, если параметра нет (самые новые)
     */
    public function __construct(
        public UserId $actorId,
        public string $companionId,
        public ?string $limit = null,
        public ?string $before = null,
    ) {
    }
}
