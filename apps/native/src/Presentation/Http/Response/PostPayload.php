<?php

declare(strict_types=1);

namespace SocialNetwork\NativeApp\Presentation\Http\Response;

use SocialNetwork\Core\Domain\Post\Post;

/**
 * Пост в виде схемы Post из docs/openapi.json. Имя поля authorUserId в camelCase (в спецификации OTUS author_user_id).
 * Чистая функция без состояния, создавать экземпляры не нужно.
 */
final class PostPayload
{
    private function __construct()
    {
    }

    /**
     * @return array<string, string>
     */
    public static function fromPost(Post $post): array
    {
        return [
            'id' => $post->id->value,
            'text' => $post->text->value,
            'authorUserId' => $post->authorId->value,
        ];
    }
}
