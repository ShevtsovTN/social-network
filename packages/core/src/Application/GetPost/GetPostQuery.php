<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetPost;

final readonly class GetPostQuery
{
    public function __construct(public string $postId)
    {
    }
}
