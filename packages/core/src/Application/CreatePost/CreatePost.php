<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\CreatePost;

use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\PostId;

interface CreatePost
{
    /**
     * @return PostId идентификатор нового поста
     *
     * @throws InvalidPostData если текст не прошёл валидацию (field = text)
     */
    public function handle(CreatePostCommand $command): PostId;
}
