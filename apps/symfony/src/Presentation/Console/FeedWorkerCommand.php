<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Presentation\Console;

use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEvent;
use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEventCommand;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Воркер ленты: читает события из Redis Streams и обновляет кэши (fan-out новых постов, backfill после добавления друга,
 * очистка после удаления). Бизнес-логика целиком в core (ProcessNextFeedEvent), здесь только цикл.
 *
 * Падает при любой ошибке инфраструктуры (PostgreSQL или Redis недоступны, соединение устарело) с кодом 1: перезапуск
 * (restart в docker compose) надёжнее, чем попытки переподключиться. Необработанное событие не подтверждено и придёт снова.
 * Остановка по SIGTERM прерывает процесс сразу: обработка событий идемпотентна, незавершённое событие переобработается.
 * Запускать от www-data (как и остальные команды), иначе кэш контейнера Symfony получит неверного владельца.
 */
#[AsCommand(name: 'feed:work', description: 'Обрабатывает события ленты постов из Redis Streams (воркер fan-out)')]
final class FeedWorkerCommand extends Command
{
    private const int WAIT_MILLISECONDS = 5000;

    public function __construct(private readonly ProcessNextFeedEvent $worker)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
        $wait = new ProcessNextFeedEventCommand(self::WAIT_MILLISECONDS);

        $errors->writeln(sprintf('[symfony] feed worker started (consumer %s)', gethostname()));

        try {
            while (true) {
                $this->worker->handle($wait);
            }
        } catch (\Throwable $exception) {
            $errors->writeln(sprintf('[symfony] feed worker stopped: %s: %s', $exception::class, $exception->getMessage()));

            return Command::FAILURE;
        }
    }
}
