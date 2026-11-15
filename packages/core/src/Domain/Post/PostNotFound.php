<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Post;

use SocialNetwork\Core\Domain\DomainException;

/**
 * Поста нет или он принадлежит другому пользователю: снаружи эти случаи не различаются,
 * чтобы не раскрывать существование чужих постов.
 */
final class PostNotFound extends DomainException
{
    public static function withId(PostId $id): self
    {
        return new self(sprintf('Post %s was not found.', $id->value));
    }
}
