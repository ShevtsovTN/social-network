<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Queue;

use SocialNetwork\Core\Application\Feed\Event\FeedEvent;
use SocialNetwork\Core\Application\Feed\Event\FriendAdded;
use SocialNetwork\Core\Application\Feed\Event\FriendRemoved;
use SocialNetwork\Core\Application\Feed\Event\PostDeleted;
use SocialNetwork\Core\Application\Feed\Event\PostEdited;
use SocialNetwork\Core\Application\Feed\Event\PostPublished;
use SocialNetwork\Core\Domain\DomainException;
use SocialNetwork\Core\Domain\Post\PostId;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Событие ленты как плоский набор строковых полей записи Redis Stream. Неизвестный тип и повреждённые поля
 * при чтении дают null: такое событие потребитель отбрасывает.
 */
final readonly class FeedEventSerializer
{
    /**
     * @return array<string, string>
     */
    public function toFields(FeedEvent $event): array
    {
        return match (true) {
            $event instanceof PostPublished => [
                'type' => 'post_published',
                'postId' => $event->postId->value,
                'authorId' => $event->authorId->value,
            ],
            $event instanceof PostEdited => [
                'type' => 'post_edited',
                'postId' => $event->postId->value,
            ],
            $event instanceof PostDeleted => [
                'type' => 'post_deleted',
                'postId' => $event->postId->value,
                'authorId' => $event->authorId->value,
            ],
            $event instanceof FriendAdded => [
                'type' => 'friend_added',
                'userId' => $event->userId->value,
                'friendId' => $event->friendId->value,
            ],
            $event instanceof FriendRemoved => [
                'type' => 'friend_removed',
                'userId' => $event->userId->value,
                'friendId' => $event->friendId->value,
            ],
            default => throw new \LogicException(sprintf('Unknown feed event %s.', $event::class)),
        };
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    public function fromFields(array $fields): ?FeedEvent
    {
        try {
            return match ($fields['type'] ?? null) {
                'post_published' => new PostPublished(self::postId($fields, 'postId'), self::userId($fields, 'authorId')),
                'post_edited' => new PostEdited(self::postId($fields, 'postId')),
                'post_deleted' => new PostDeleted(self::postId($fields, 'postId'), self::userId($fields, 'authorId')),
                'friend_added' => new FriendAdded(self::userId($fields, 'userId'), self::userId($fields, 'friendId')),
                'friend_removed' => new FriendRemoved(self::userId($fields, 'userId'), self::userId($fields, 'friendId')),
                default => null,
            };
        } catch (DomainException|\UnexpectedValueException) {
            return null;
        }
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    private static function postId(array $fields, string $name): PostId
    {
        return PostId::fromString(self::string($fields, $name));
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    private static function userId(array $fields, string $name): UserId
    {
        return UserId::fromString(self::string($fields, $name));
    }

    /**
     * @param array<array-key, mixed> $fields
     */
    private static function string(array $fields, string $name): string
    {
        $value = $fields[$name] ?? null;

        if (!is_string($value)) {
            throw new \UnexpectedValueException(sprintf('Event field "%s" is missing.', $name));
        }

        return $value;
    }
}
