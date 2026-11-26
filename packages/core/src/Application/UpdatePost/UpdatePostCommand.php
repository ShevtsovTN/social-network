<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\UpdatePost;

use SocialNetwork\Core\Domain\User\UserId;

final readonly class UpdatePostCommand
{
    public function __construct(public UserId $actorId, public string $postId, public string $text)
    {
    }
}
