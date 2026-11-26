<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\SendMessage;

use SocialNetwork\Core\Domain\User\UserId;

final readonly class SendMessageCommand
{
    public function __construct(public UserId $actorId, public string $recipientId, public string $text)
    {
    }
}
