<?php

declare(strict_types=1);

// Генератор нагрузки: GET по одному URL, фиксированное число параллельных соединений.
// Запуск: php load.php <url> <concurrency> <seconds>

[, $url, $concurrency, $seconds] = $argv;
$concurrency = (int) $concurrency;
$seconds = (int) $seconds;

$multi = curl_multi_init();
$start = microtime(true);
$deadline = $start + $seconds;
$latencies = [];
$statuses = [];
$inflight = [];

$spawn = static function () use ($multi, $url, &$inflight): void {
    $handle = curl_init($url);
    curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30]);
    curl_multi_add_handle($multi, $handle);
    $inflight[spl_object_id($handle)] = microtime(true);
};

for ($i = 0; $i < $concurrency; $i++) {
    $spawn();
}

while ($inflight !== []) {
    curl_multi_exec($multi, $running);
    curl_multi_select($multi, 0.05);

    while (($info = curl_multi_info_read($multi)) !== false) {
        $handle = $info['handle'];
        $key = spl_object_id($handle);
        $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $statuses[$status] = ($statuses[$status] ?? 0) + 1;
        $latencies[] = microtime(true) - $inflight[$key];
        unset($inflight[$key]);
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);

        if (microtime(true) < $deadline) {
            $spawn();
        }
    }
}

$elapsed = microtime(true) - $start;
sort($latencies);
$total = count($latencies);
$percentile = static fn (float $share): float => round($latencies[(int) min($total - 1, floor($total * $share))] * 1000, 1);
ksort($statuses);

printf(
    "rps=%.0f requests=%d statuses=%s p50=%sms p95=%sms p99=%sms\n",
    $total / $elapsed,
    $total,
    json_encode($statuses),
    $percentile(0.5),
    $percentile(0.95),
    $percentile(0.99),
);
