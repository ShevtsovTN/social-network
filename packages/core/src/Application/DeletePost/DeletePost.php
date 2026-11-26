<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\DeletePost;

use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\PostNotFound;

interface DeletePost
{
    /**
     * @throws InvalidPostData если id не UUID (field = id)
     * @throws PostNotFound если поста нет или он принадлежит другому пользователю
     */
    public function handle(DeletePostCommand $command): void;
}
