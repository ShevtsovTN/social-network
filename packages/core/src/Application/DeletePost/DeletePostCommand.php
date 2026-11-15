<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\DeletePost;

use SocialNetwork\Core\Domain\User\UserId;

final readonly class DeletePostCommand
{
    public function __construct(public UserId $actorId, public string $postId)
    {
    }
}
