<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\DeletePost;

use SocialNetwork\Core\Application\Feed\Event\PostDeleted;
use SocialNetwork\Core\Application\Port\FeedEventPublisher;
use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\Post\PostNotFound;

final readonly class DeletePostHandler implements DeletePost
{
    public function __construct(
        private PostRepository $posts,
        private FeedEventPublisher $events,
    ) {
    }

    public function handle(DeletePostCommand $command): void
    {
        $id = PostId::fromString($command->postId);
        $post = $this->posts->findById($id);

        if ($post === null || !$post->isWrittenBy($command->actorId)) {
            throw PostNotFound::withId($id);
        }

        $this->posts->delete($id);
        $this->events->publish(new PostDeleted($id, $post->authorId));
    }
}
