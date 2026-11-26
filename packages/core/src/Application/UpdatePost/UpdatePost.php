<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\UpdatePost;

use SocialNetwork\Core\Domain\Post\InvalidPostData;
use SocialNetwork\Core\Domain\Post\PostNotFound;

interface UpdatePost
{
    /**
     * @throws InvalidPostData если id не UUID (field = id) или текст не прошёл валидацию (field = text)
     * @throws PostNotFound если поста нет или он принадлежит другому пользователю
     */
    public function handle(UpdatePostCommand $command): void;
}
