<?php

declare(strict_types=1);

/**
 * Pure helpers for script/ci-streak.php (#36401).
 */

/**
 * @return array{
 *   issue:int,
 *   ci_green_streak_days:int,
 *   last_green_master_sha:string,
 *   last_green_day:string,
 *   gates:list<string>,
 *   notes:string
 * }
 */
function ci_streak_defaults(): array
{
    return [
        'issue' => 36401,
        'ci_green_streak_days' => 0,
        'last_green_master_sha' => '',
        'last_green_day' => '',
        'gates' => [],
        'notes' => 'Local Docker gates; GHA billing-disabled — not a remote check streak (#36401)',
    ];
}

/**
 * @return array{
 *   issue:int,
 *   ci_green_streak_days:int,
 *   last_green_master_sha:string,
 *   last_green_day:string,
 *   gates:list<string>,
 *   notes:string
 * }
 */
function ci_streak_load(string $path): array
{
    $defaults = ci_streak_defaults();
    if (!is_readable($path)) {
        return $defaults;
    }
    $decoded = json_decode((string) file_get_contents($path), true);
    if (!is_array($decoded)) {
        return $defaults;
    }

    return [
        'issue' => (int) ($decoded['issue'] ?? 36401),
        'ci_green_streak_days' => max(0, (int) ($decoded['ci_green_streak_days'] ?? 0)),
        'last_green_master_sha' => (string) ($decoded['last_green_master_sha'] ?? ''),
        'last_green_day' => (string) ($decoded['last_green_day'] ?? ''),
        'gates' => array_values(array_map('strval', is_array($decoded['gates'] ?? null) ? $decoded['gates'] : [])),
        'notes' => (string) ($decoded['notes'] ?? $defaults['notes']),
    ];
}

/**
 * @param array{
 *   issue:int,
 *   ci_green_streak_days:int,
 *   last_green_master_sha:string,
 *   last_green_day:string,
 *   gates:list<string>,
 *   notes:string
 * } $prev
 * @return array{
 *   issue:int,
 *   ci_green_streak_days:int,
 *   last_green_master_sha:string,
 *   last_green_day:string,
 *   gates:list<string>,
 *   notes:string
 * }
 */
function ci_streak_record(array $prev, string $sha, string $day, ?array $gates = null): array
{
    $gates = $gates ?? ['apply-patches', 'bootstrap-inventory', 'north-star5-verify-fast'];
    $streak = (int) $prev['ci_green_streak_days'];
    $prevDay = (string) $prev['last_green_day'];

    if ($prevDay === $day) {
        $streak = max(1, $streak);
    } elseif ($prevDay === '') {
        $streak = 1;
    } else {
        $prevTs = strtotime($prevDay . ' UTC');
        $dayTs = strtotime($day . ' UTC');
        if (false === $prevTs || false === $dayTs) {
            $streak = 1;
        } elseif ($dayTs === $prevTs + 86400) {
            $streak = max(1, $streak) + 1;
        } elseif ($dayTs > $prevTs + 86400) {
            $streak = 1;
        } else {
            return $prev;
        }
    }

    return [
        'issue' => 36401,
        'ci_green_streak_days' => $streak,
        'last_green_master_sha' => strtolower($sha),
        'last_green_day' => $day,
        'gates' => array_values($gates),
        'notes' => 'Local Docker gates; GHA billing-disabled — not a remote check streak (#36401)',
    ];
}
