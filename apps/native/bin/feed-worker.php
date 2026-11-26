<?php

declare(strict_types=1);

/**
 * Воркер ленты: читает события из Redis Streams и обновляет кэши (fan-out новых постов, backfill после добавления друга,
 * очистка после удаления). Бизнес-логика целиком в core (ProcessNextFeedEvent), здесь только цикл.
 *
 * Падает при любой ошибке инфраструктуры (PostgreSQL или Redis недоступны, соединение устарело) с кодом 1: перезапуск
 * (restart в docker compose) надёжнее, чем попытки переподключиться. Необработанное событие не подтверждено и придёт снова.
 * Остановка по SIGTERM прерывает процесс сразу: обработка событий идемпотентна, незавершённое событие переобработается.
 */

use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEventCommand;
use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEventHandler;

require __DIR__ . '/../vendor/autoload.php';

if (PHP_SAPI !== 'cli') {
    exit(1);
}

try {
    $container = require __DIR__ . '/../config/container.php';
    $worker = $container[ProcessNextFeedEventHandler::class]();
    $wait = new ProcessNextFeedEventCommand(5000);

    fwrite(STDERR, sprintf("[native] feed worker started (consumer %s)\n", gethostname()));

    while (true) {
        $worker->handle($wait);
    }
} catch (Throwable $exception) {
    fwrite(STDERR, sprintf("[native] feed worker stopped: %s: %s\n", $exception::class, $exception->getMessage()));
    exit(1);
}
