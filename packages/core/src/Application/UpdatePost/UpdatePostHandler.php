<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\UpdatePost;

use SocialNetwork\Core\Application\Feed\Event\PostEdited;
use SocialNetwork\Core\Application\Port\FeedEventPublisher;
use SocialNetwork\Core\Application\Port\PostRepository;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\Post\PostNotFound;
use SocialNetwork\Core\Domain\Post\PostText;

final readonly class UpdatePostHandler implements UpdatePost
{
    public function __construct(
        private PostRepository $posts,
        private FeedEventPublisher $events,
    ) {
    }

    public function handle(UpdatePostCommand $command): void
    {
        $id = PostId::fromString($command->postId);
        $text = PostText::fromString($command->text);

        // Автор поста неизменяем, поэтому «прочитать, проверить, записать» безопасно без блокировки:
        // если пост удалят между чтением и записью, update просто ничего не изменит.
        $post = $this->posts->findById($id);

        if ($post === null || !$post->isWrittenBy($command->actorId)) {
            throw PostNotFound::withId($id);
        }

        $this->posts->update($post->edit($text));
        $this->events->publish(new PostEdited($id));
    }
}
