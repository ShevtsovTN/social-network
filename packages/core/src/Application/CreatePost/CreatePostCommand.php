<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\CreatePost;

use SocialNetwork\Core\Domain\User\UserId;

final readonly class CreatePostCommand
{
    public function __construct(public UserId $actorId, public string $text)
    {
    }
}
