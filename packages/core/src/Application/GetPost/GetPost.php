<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetPost;

use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostNotFound;

interface GetPost
{
    /**
     * @throws InvalidPostData если id не UUID (field = id)
     * @throws PostNotFound
     */
    public function handle(GetPostQuery $query): Post;
}
