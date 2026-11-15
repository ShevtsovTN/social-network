#!/usr/bin/env bash
# Сверка Infrastructure ленты постов в apps/native и apps/symfony (make compare-infrastructure).
#
# Приложения не зависят друг от друга, поэтому эти классы существуют в двух копиях. По правилам проекта реализации
# эквивалентны по подходу (одни и те же запросы, Lua-скрипты, TTL, таймауты), иначе нагрузочное сравнение сравнивает
# не фреймворки, а разные реализации. Скрипт нормализует namespace приложения и префикс в логах и показывает любое
# расхождение. Код выхода: 0 — копии совпадают, 1 — есть расхождения или файла нет в одном из приложений.
set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

files=(
    src/Infrastructure/Persistence/PdoPostRepository.php
    src/Infrastructure/Persistence/PdoFriendshipRepository.php
    src/Infrastructure/Persistence/PdoFeedSource.php
    src/Infrastructure/Redis/RedisConnection.php
    src/Infrastructure/Redis/RedisKeyspace.php
    src/Infrastructure/Redis/LuaScript.php
    src/Infrastructure/Redis/RedisFeedCache.php
    src/Infrastructure/Redis/RedisPostCache.php
    src/Infrastructure/Queue/FeedEventSerializer.php
    src/Infrastructure/Queue/RedisFeedEventPublisher.php
    src/Infrastructure/Queue/RedisFeedEventConsumer.php
    src/Infrastructure/Security/RandomPostIdGenerator.php
    tests/Integration/PdoFeedStorage.php
    tests/Integration/UsesPdoFeedStorage.php
    tests/Integration/PdoPostRepositoryTest.php
    tests/Integration/PdoFriendshipRepositoryTest.php
    tests/Integration/PdoFeedSourceTest.php
    tests/Integration/UsesIsolatedRedis.php
    tests/Integration/RedisFeedCacheTest.php
    tests/Integration/RedisPostCacheTest.php
    tests/Integration/RedisFeedEventChannel.php
    tests/Integration/RedisFeedEventQueueTest.php
    tests/Integration/FeedEventSerializerTest.php
    tests/Integration/RandomPostIdGeneratorTest.php
    tests/Integration/RedisFeedCacheBehaviourTest.php
    tests/Integration/RedisFeedEventConsumerBehaviourTest.php
    tests/Integration/FeedFlowTest.php
)

normalize() {
    sed -e 's/SocialNetwork\\NativeApp/SocialNetwork\\App/g' \
        -e 's/SocialNetwork\\SymfonyApp/SocialNetwork\\App/g' \
        -e 's/\[native\]/[app]/g' \
        -e 's/\[symfony\]/[app]/g' "$1"
}

differences=0

for file in "${files[@]}"; do
    native="$root/apps/native/$file"
    symfony="$root/apps/symfony/$file"

    if [[ ! -f "$native" || ! -f "$symfony" ]]; then
        echo "  MISSING  $file (native: $([[ -f "$native" ]] && echo есть || echo нет), symfony: $([[ -f "$symfony" ]] && echo есть || echo нет))"
        differences=$((differences + 1))
        continue
    fi

    if diff_output="$(diff -u <(normalize "$native") <(normalize "$symfony"))"; then
        echo "  ok       $file"
    else
        echo "  DIFF     $file"
        echo "$diff_output" | sed 's/^/           /'
        differences=$((differences + 1))
    fi
done

echo
echo "${#files[@]} файлов, расхождений: $differences"

[[ $differences -eq 0 ]]
