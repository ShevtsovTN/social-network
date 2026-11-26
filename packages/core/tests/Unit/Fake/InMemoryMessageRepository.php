<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Tests\Unit\Fake;

use SocialNetwork\Core\Application\Port\MessageRepository;
use SocialNetwork\Core\Domain\Dialog\DialogId;
use SocialNetwork\Core\Domain\Dialog\DialogPageRequest;
use SocialNetwork\Core\Domain\Dialog\Message;

final class InMemoryMessageRepository implements MessageRepository
{
    /** @var array<string, array<string, Message>> диалог → id сообщения → сообщение */
    private array $dialogs = [];

    public function add(Message $message): void
    {
        if (isset($this->dialogs[$message->dialogId->value][$message->id->value])) {
            throw new \LogicException(sprintf('Message %s already exists.', $message->id->value));
        }

        $this->dialogs[$message->dialogId->value][$message->id->value] = $message;
    }

    public function findPage(DialogId $dialogId, DialogPageRequest $page): array
    {
        $messages = $this->dialogs[$dialogId->value] ?? [];

        if ($page->before !== null) {
            $before = $page->before->value;
            $messages = array_filter(
                $messages,
                static fn (Message $message): bool => strcmp($message->id->value, $before) < 0,
            );
        }

        ksort($messages, SORT_STRING);

        return array_values(array_slice($messages, -$page->limit));
    }

    public function count(): int
    {
        return array_sum(array_map(count(...), $this->dialogs));
    }
}
