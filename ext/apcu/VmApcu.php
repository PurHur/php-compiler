<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\VM\Variable;

/**
 * In-process APCu user cache (PECL apcu / php-src ext/apcu; #6574).
 *
 * PHP-in-PHP hashtable with TTL — no runtime/*.c / SHM. Process-local only (v1).
 */
final class VmApcu
{
    /**
     * @var array<string, array{value: Variable, expires: int}>
     */
    private static array $store = [];

    public static function reset(): void
    {
        self::$store = [];
    }

    public static function clear(): bool
    {
        self::$store = [];

        return true;
    }

    public static function store(string $key, Variable $value, int $ttl = 0): bool
    {
        $slot = new Variable();
        $slot->duplicateFrom($value->resolveIndirect());
        $expires = $ttl > 0 ? \time() + $ttl : 0;
        self::$store[$key] = ['value' => $slot, 'expires' => $expires];

        return true;
    }

    public static function fetch(string $key, ?bool &$success = null): ?Variable
    {
        if (!isset(self::$store[$key])) {
            $success = false;

            return null;
        }
        $entry = self::$store[$key];
        if ($entry['expires'] > 0 && $entry['expires'] <= \time()) {
            unset(self::$store[$key]);
            $success = false;

            return null;
        }
        $success = true;
        $out = new Variable();
        $out->duplicateFrom($entry['value']);

        return $out;
    }

    public static function exists(string $key): bool
    {
        $success = false;
        self::fetch($key, $success);

        return $success;
    }

    public static function delete(string $key): bool
    {
        if (!isset(self::$store[$key])) {
            return false;
        }
        unset(self::$store[$key]);

        return true;
    }

    /**
     * Minimal apcu_cache_info() shape (user cache only).
     *
     * @return array<string, mixed>
     */
    public static function cacheInfo(bool $limited = false): array
    {
        self::expireStale();
        $info = [
            'num_entries' => \count(self::$store),
            'ttl' => 0,
            'mem_size' => 0,
            'num_hits' => 0,
            'num_misses' => 0,
            'start_time' => 0,
        ];
        if (!$limited) {
            $list = [];
            foreach (self::$store as $key => $entry) {
                $list[] = [
                    'info' => $key,
                    'ttl' => $entry['expires'] > 0 ? \max(0, $entry['expires'] - \time()) : 0,
                ];
            }
            $info['cache_list'] = $list;
        }

        return $info;
    }

    private static function expireStale(): void
    {
        $now = \time();
        foreach (self::$store as $key => $entry) {
            if ($entry['expires'] > 0 && $entry['expires'] <= $now) {
                unset(self::$store[$key]);
            }
        }
    }
}
