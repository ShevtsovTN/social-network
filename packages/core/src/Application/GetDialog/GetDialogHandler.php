<?php

declare(strict_types=1);

namespace SocialNetwork\Core\Application\GetDialog;

use SocialNetwork\Core\Application\Port\MessageRepository;
use SocialNetwork\Core\Domain\Dialog\DialogId;
use SocialNetwork\Core\Domain\Dialog\DialogPageRequest;
use SocialNetwork\Core\Domain\User\UserId;

final readonly class GetDialogHandler implements GetDialog
{
    public function __construct(private MessageRepository $messages)
    {
    }

    public function handle(GetDialogQuery $query): array
    {
        // Диалог всегда строится от текущего пользователя: чужую переписку этим запросом не получить.
        $dialogId = DialogId::between($query->actorId, UserId::fromString($query->companionId, 'companionId'));

        return $this->messages->findPage($dialogId, DialogPageRequest::fromInput($query->limit, $query->before));
    }
}
