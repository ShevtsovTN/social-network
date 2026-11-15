<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Domain\Post;

use SocialNetwork\Core\Domain\User\UserId;

final readonly class Post
{
    private function __construct(
        public PostId $id,
        public UserId $authorId,
        public PostText $text,
        public \DateTimeImmutable $createdAt,
    ) {
    }

    /**
     * Создание нового поста.
     */
    public static function create(PostId $id, UserId $authorId, PostText $text, \DateTimeImmutable $now): self
    {
        return new self($id, $authorId, $text, $now);
    }

    /**
     * Восстановление уже существующего поста из хранилища. Используется репозиториями.
     */
    public static function reconstitute(PostId $id, UserId $authorId, PostText $text, \DateTimeImmutable $createdAt): self
    {
        return new self($id, $authorId, $text, $createdAt);
    }

    /**
     * Новая версия поста с другим текстом. Автор и время создания не меняются,
     * поэтому порядок поста в лентах тоже.
     */
    public function edit(PostText $text): self
    {
        return new self($this->id, $this->authorId, $text, $this->createdAt);
    }

    public function isWrittenBy(UserId $userId): bool
    {
        return $this->authorId->equals($userId);
    }
}
