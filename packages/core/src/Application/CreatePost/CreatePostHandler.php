<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\CreatePost;

use SocialNetwork\Core\Application\Feed\Event\PostPublished;
use SocialNetwork\Core\Application\Port\Clock;
use SocialNetwork\Core\Application\Port\FeedEventPublisher;
use SocialNetwork\Core\Application\Port\PostIdGenerator;
use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Domain\Post\Post;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\Post\PostText;

final readonly class CreatePostHandler implements CreatePost
{
    public function __construct(
        private PostRepository $posts,
        private PostIdGenerator $idGenerator,
        private Clock $clock,
        private FeedEventPublisher $events,
    ) {
    }

    public function handle(CreatePostCommand $command): PostId
    {
        $post = Post::create(
            $this->idGenerator->generate(),
            $command->actorId,
            PostText::fromString($command->text),
            $this->clock->now(),
        );

        $this->posts->add($post);
        $this->events->publish(new PostPublished($post->id, $post->authorId));

        return $post->id;
    }
}
