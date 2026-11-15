<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Tests\Unit;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEvent;
use SocialNetwork\Core\Application\ProcessNextFeedEvent\ProcessNextFeedEventCommand;
use SocialNetwork\SymfonyApp\Presentation\Console\FeedWorkerCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class FeedWorkerCommandTest extends TestCase
{
    public function testKeepsProcessingUntilInfrastructureFailsThenExitsWithAnError(): void
    {
        $worker = new class implements ProcessNextFeedEvent {
            public int $calls = 0;

            public function handle(ProcessNextFeedEventCommand $command): bool
            {
                ++$this->calls;

                // Две пустые выдачи и обработанное событие, затем сбой Redis.
                return match ($this->calls) {
                    1, 2 => false,
                    3 => true,
                    default => throw new \RuntimeException('connection to redis lost'),
                };
            }
        };
        $tester = new CommandTester(new FeedWorkerCommand($worker));

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(4, $worker->calls);
        self::assertStringContainsString('feed worker started', $tester->getDisplay());
        self::assertStringContainsString('feed worker stopped: RuntimeException: connection to redis lost', $tester->getDisplay());
    }

    public function testWaitsForEventsInBlocksOfFiveSeconds(): void
    {
        $worker = new class implements ProcessNextFeedEvent {
            public ?int $waited = null;

            public function handle(ProcessNextFeedEventCommand $command): bool
            {
                $this->waited = $command->waitMilliseconds;

                throw new \RuntimeException('stop');
            }
        };

        (new CommandTester(new FeedWorkerCommand($worker)))->execute([]);

        self::assertSame(5000, $worker->waited);
    }
}
