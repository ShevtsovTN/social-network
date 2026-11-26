<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\SendMessage;

use SocialNetwork\Core\Application\Port\Clock;
use SocialNetwork\Core\Application\Port\MessageIdGenerator;
use SocialNetwork\Core\Application\Port\MessageRepository;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\Dialog\Message;
use SocialNetwork\Core\Domain\Dialog\MessageText;
use SocialNetwork\Core\Domain\User\UserId;
use SocialNetwork\Core\Domain\User\UserNotFound;

final readonly class SendMessageHandler implements SendMessage
{
    public function __construct(
        private UserRepository $users,
        private MessageRepository $messages,
        private MessageIdGenerator $idGenerator,
        private Clock $clock,
    ) {
    }

    public function handle(SendMessageCommand $command): void
    {
        $recipientId = UserId::fromString($command->recipientId, 'recipientId');
        $message = Message::send(
            $this->idGenerator->generate(),
            $command->actorId,
            $recipientId,
            MessageText::fromString($command->text),
            $this->clock->now(),
        );

        // Существование получателя проверяется после правил домена: неверный запрос не доходит до БД.
        if ($this->users->findById($recipientId) === null) {
            throw UserNotFound::withId($recipientId);
        }

        $this->messages->add($message);
    }
}
