<?php

declare(strict_types=1);

namespace SocialNetwork\CoreContract;

use PHPUnit\Framework\TestCase;
use SocialNetwork\Core\Application\Port\MessageRepository;
use SocialNetwork\Core\Domain\Dialog\DialogPageRequest;
use SocialNetwork\Core\Domain\Dialog\Message;
use SocialNetwork\Core\Domain\Dialog\MessageText;

/**
 * Контракт порта MessageRepository. Каждая реализация (in-memory, PDO в native, PDO в symfony)
 * наследует этот класс и возвращает из createRepository() репозиторий над пустым хранилищем.
 */
abstract class MessageRepositoryContractTest extends TestCase
{
    abstract protected function createRepository(): MessageRepository;

    public function testDialogWithoutMessagesIsEmpty(): void
    {
        $repository = $this->createRepository();

        self::assertSame([], $this->page($repository));
    }

    public function testRestoresEveryFieldIncludingMicroseconds(): void
    {
        $repository = $this->createRepository();
        $message = MessageFixture::message(1, 2, 1, 'Привет', 10, 123456);

        $repository->add($message);

        $found = $this->page($repository);
        self::assertCount(1, $found);
        self::assertSame($message->id->value, $found[0]->id->value);
        self::assertSame($message->dialogId->value, $found[0]->dialogId->value);
        self::assertSame($message->senderId->value, $found[0]->senderId->value);
        self::assertSame($message->recipientId->value, $found[0]->recipientId->value);
        self::assertSame($message->text->value, $found[0]->text->value);
        self::assertSame(
            $message->sentAt->format('Y-m-d H:i:s.u'),
            $found[0]->sentAt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s.u'),
        );
    }

    public function testKeepsLongTextWithLineBreaksQuotesAndUnicode(): void
    {
        $repository = $this->createRepository();
        $texts = [
            1 => "Первая строка\nВторая строка с «кавычками», \"двойными\" и 'одинарными'",
            2 => "'; DROP TABLE dialog_messages; -- \\ % _ \$1 :name ?",
            3 => str_repeat('я', MessageText::MAX_LENGTH),
            4 => 'Эмодзи 🙂 и иероглифы 漢字',
        ];

        foreach ($texts as $number => $text) {
            $repository->add(MessageFixture::message($number, text: $text));
        }

        self::assertSame(
            array_values($texts),
            array_map(static fn (Message $message): string => $message->text->value, $this->page($repository)),
        );
    }

    public function testRejectsMessageWithTheSameIdInTheDialog(): void
    {
        $repository = $this->createRepository();
        $repository->add(MessageFixture::message(1));

        $this->expectException(\Throwable::class);

        $repository->add(MessageFixture::message(1, 2, 1, 'Другой текст'));
    }

    public function testBothDirectionsBelongToOneDialogOrderedFromOldToNew(): void
    {
        $repository = $this->createRepository();
        $repository->add(MessageFixture::message(3, 1, 2));
        $repository->add(MessageFixture::message(1, 1, 2));
        $repository->add(MessageFixture::message(2, 2, 1));

        self::assertSame(MessageFixture::expectedIds(1, 2, 3), MessageFixture::ids($this->page($repository)));
        self::assertSame(
            MessageFixture::expectedIds(1, 2, 3),
            MessageFixture::ids($repository->findPage(MessageFixture::dialogId(2, 1), DialogPageRequest::fromInput(null, null))),
        );
    }

    public function testOtherDialogsOfTheSameUsersAreNotIncluded(): void
    {
        $repository = $this->createRepository();
        $repository->add(MessageFixture::message(1, 1, 2));
        $repository->add(MessageFixture::message(2, 1, 3));
        $repository->add(MessageFixture::message(3, 3, 2));
        $repository->add(MessageFixture::message(4, 2, 1));

        self::assertSame(MessageFixture::expectedIds(1, 4), MessageFixture::ids($this->page($repository)));
        self::assertSame(
            MessageFixture::expectedIds(2),
            MessageFixture::ids($repository->findPage(MessageFixture::dialogId(1, 3), DialogPageRequest::fromInput(null, null))),
        );
    }

    public function testOrderIsDefinedByIdAndNotBySendingTime(): void
    {
        $repository = $this->createRepository();
        $repository->add(MessageFixture::message(1, sentAfterSeconds: 30));
        $repository->add(MessageFixture::message(2, sentAfterSeconds: 20));
        $repository->add(MessageFixture::message(3, sentAfterSeconds: 10));

        self::assertSame(MessageFixture::expectedIds(1, 2, 3), MessageFixture::ids($this->page($repository)));
        self::assertSame(MessageFixture::expectedIds(2, 3), MessageFixture::ids($this->page($repository, '2')));
    }

    public function testDefaultPageHoldsTheNewestFiftyMessages(): void
    {
        $repository = $this->createRepository();
        $this->addMessages($repository, 52);

        self::assertSame(MessageFixture::expectedIds(...range(3, 52)), MessageFixture::ids($this->page($repository)));
    }

    public function testLimitKeepsTheNewestMessagesFromOldToNew(): void
    {
        $repository = $this->createRepository();
        $this->addMessages($repository, 5);

        self::assertSame(MessageFixture::expectedIds(4, 5), MessageFixture::ids($this->page($repository, '2')));
    }

    public function testBeforeReturnsTheNewestMessagesOlderThanTheCursor(): void
    {
        $repository = $this->createRepository();
        $this->addMessages($repository, 5);

        self::assertSame(MessageFixture::expectedIds(2, 3), MessageFixture::ids($this->page($repository, '2', 4)));
        self::assertSame(MessageFixture::expectedIds(1, 2, 3), MessageFixture::ids($this->page($repository, '10', 4)));
    }

    public function testBeforeTheOldestMessageIsEmpty(): void
    {
        $repository = $this->createRepository();
        $this->addMessages($repository, 3);

        self::assertSame([], $this->page($repository, '2', 1));
    }

    public function testCursorDoesNotHaveToBeAnExistingMessage(): void
    {
        $repository = $this->createRepository();
        $repository->add(MessageFixture::message(10));
        $repository->add(MessageFixture::message(30));
        // Курсор — id сообщения другого диалога: это всё равно только граница по id.
        $repository->add(MessageFixture::message(20, 1, 3));

        self::assertSame(MessageFixture::expectedIds(10), MessageFixture::ids($this->page($repository, null, 20)));
        self::assertSame(MessageFixture::expectedIds(10, 30), MessageFixture::ids($this->page($repository, null, 99)));
    }

    public function testScrollingBackVisitsEveryMessageOnceWhileNewOnesArrive(): void
    {
        $repository = $this->createRepository();
        $this->addMessages($repository, 7);
        $dialog = MessageFixture::dialogId(1, 2);

        $seen = [];
        $before = null;
        $arriving = 8;

        do {
            $page = $repository->findPage($dialog, DialogPageRequest::fromInput('3', $before));
            $seen = [...MessageFixture::ids($page), ...$seen];
            $before = $page === [] ? null : $page[0]->id->value;
            // Пока история листается, приходят новые сообщения: уже загруженные и более старые страницы они не сдвигают.
            $repository->add(MessageFixture::message($arriving++));
        } while (count($page) === 3);

        self::assertSame(MessageFixture::expectedIds(...range(1, 7)), $seen);
    }

    /**
     * @return list<Message> страница диалога пользователей 1 и 2
     */
    private function page(MessageRepository $repository, ?string $limit = null, ?int $before = null): array
    {
        return $repository->findPage(
            MessageFixture::dialogId(1, 2),
            DialogPageRequest::fromInput($limit, $before === null ? null : MessageFixture::messageId($before)->value),
        );
    }

    /**
     * Добавляет в диалог пользователей 1 и 2 сообщения с номерами от 1 до $count.
     */
    private function addMessages(MessageRepository $repository, int $count): void
    {
        foreach (range(1, $count) as $number) {
            // Направления чередуются.
            $repository->add(MessageFixture::message($number, 2 - $number % 2, 1 + $number % 2));
        }
    }
}
