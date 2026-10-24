<?php

declare(strict_types=1);

namespace SocialNetwork\SymfonyApp\Infrastructure\Persistence;

use JsonException;
use PDO;
use SocialNetwork\Core\Application\Port\UserRepository;
use SocialNetwork\Core\Domain\User\BirthDate;
use SocialNetwork\Core\Domain\User\City;
use SocialNetwork\Core\Domain\User\Gender;
use SocialNetwork\Core\Domain\User\Interests;
use SocialNetwork\Core\Domain\User\Name;
use SocialNetwork\Core\Domain\User\NamePrefix;
use SocialNetwork\Core\Domain\User\PasswordHash;
use SocialNetwork\Core\Domain\User\User;
use SocialNetwork\Core\Domain\User\UserId;

/**
 * Интересы хранятся в одной колонке TEXT (без отдельной таблицы и индексов,
 * как требует задание): пустой список — пустая строка, иначе JSON-массив.
 * JSON, а не разделитель вроде запятой, потому что сам интерес может
 * содержать любые символы (запятые, кавычки, юникод).
 */
final readonly class PdoUserRepository implements UserRepository
{
    public function __construct(private PDO $connection)
    {
    }

    public function save(User $user): void
    {
        $statement = $this->connection->prepare(
            'INSERT INTO users (id, first_name, last_name, birth_date, gender, interests, city, password_hash)
             VALUES (:id, :first_name, :last_name, :birth_date, :gender, :interests, :city, :password_hash)',
        );

        $statement->execute([
            'id' => $user->id->value,
            'first_name' => $user->firstName->value,
            'last_name' => $user->lastName->value,
            'birth_date' => $user->birthDate->toString(),
            'gender' => $user->gender->value,
            'interests' => self::interestsToColumn($user->interests),
            'city' => $user->city->value,
            'password_hash' => $user->passwordHash->value,
        ]);
    }

    public function findById(UserId $id): ?User
    {
        $statement = $this->connection->prepare(
            'SELECT id, first_name, last_name, birth_date, gender, interests, city, password_hash
             FROM users WHERE id = :id',
        );
        $statement->execute(['id' => $id->value]);
        $row = $statement->fetch();

        return $row === false ? null : self::userFromRow($row);
    }

    public function searchByNamePrefix(NamePrefix $firstName, NamePrefix $lastName): array
    {
        $statement = $this->connection->prepare(
            "SELECT id, first_name, last_name, birth_date, gender, interests, city, password_hash
             FROM users
             WHERE first_name LIKE :first_name ESCAPE '\\' AND last_name LIKE :last_name ESCAPE '\\'
             ORDER BY id",
        );
        $statement->execute([
            'first_name' => self::likePrefixPattern($firstName),
            'last_name' => self::likePrefixPattern($lastName),
        ]);

        return array_map(self::userFromRow(...), $statement->fetchAll());
    }

    /**
     * Префикс ищется буквально: спецсимволы LIKE (%, _ и сам символ экранирования) экранируются,
     * в конец добавляется %.
     */
    private static function likePrefixPattern(NamePrefix $prefix): string
    {
        return strtr($prefix->value, ['\\' => '\\\\', '%' => '\\%', '_' => '\\_']) . '%';
    }

    /**
     * @param array<string, string> $row
     *
     * @throws JsonException
     */
    private static function userFromRow(array $row): User
    {
        return User::reconstitute(
            UserId::fromString($row['id']),
            Name::fromString($row['first_name'], 'firstName'),
            Name::fromString($row['last_name'], 'lastName'),
            BirthDate::fromString($row['birth_date']),
            Gender::from($row['gender']),
            self::interestsFromColumn($row['interests']),
            City::fromString($row['city']),
            PasswordHash::fromString($row['password_hash']),
        );
    }

    /**
     * @throws JsonException
     */
    private static function interestsToColumn(Interests $interests): string
    {
        if ($interests->isEmpty()) {
            return '';
        }

        return json_encode($interests->values, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    /**
     * @throws JsonException
     */
    private static function interestsFromColumn(string $value): Interests
    {
        if ($value === '') {
            return Interests::none();
        }

        /** @var list<string> $decoded */
        $decoded = json_decode($value, true, flags: JSON_THROW_ON_ERROR);

        return Interests::fromList($decoded);
    }
}
