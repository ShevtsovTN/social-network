<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetPost;

use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\Post\PostNotFound;

final readonly class GetPostHandler implements GetPost
{
    public function __construct(private PostRepository $posts)
    {
    }

    public function handle(GetPostQuery $query): Post
    {
        $id = PostId::fromString($query->postId);

        return $this->posts->findById($id) ?? throw PostNotFound::withId($id);
    }
}
