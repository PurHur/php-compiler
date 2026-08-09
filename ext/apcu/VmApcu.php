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
        return self::storeExclusive($key, $value, $ttl, false);
    }

    /**
     * apcu_store / apcu_add — exclusive=true only inserts when the key is absent (PECL apc_cache_store).
     */
    public static function storeExclusive(string $key, Variable $value, int $ttl = 0, bool $exclusive = false): bool
    {
        if ($exclusive && self::exists($key)) {
            return false;
        }
        $slot = new Variable();
        $slot->duplicateFrom($value->resolveIndirect());
        $expires = $ttl > 0 ? \time() + $ttl : 0;
        self::$store[$key] = ['value' => $slot, 'expires' => $expires];

        return true;
    }

    public static function add(string $key, Variable $value, int $ttl = 0): bool
    {
        return self::storeExclusive($key, $value, $ttl, true);
    }

    /**
     * apcu_inc / apcu_dec — PECL php_apc_update(insert_if_not_found=1).
     *
     * @return int|false
     */
    public static function adjust(string $key, int $step, int $ttl = 0)
    {
        $success = false;
        $current = self::fetch($key, $success);
        if (!$success || null === $current) {
            $slot = new Variable();
            $slot->int($step);
            self::store($key, $slot, $ttl);

            return $step;
        }
        if (Variable::TYPE_INTEGER !== $current->type) {
            return false;
        }
        $next = $current->toInt() + $step;
        $slot = new Variable();
        $slot->int($next);
        // Preserve remaining TTL when refreshing an existing entry without a new ttl.
        $expires = self::$store[$key]['expires'] ?? 0;
        if ($ttl > 0) {
            $expires = \time() + $ttl;
        }
        self::$store[$key] = ['value' => $slot, 'expires' => $expires];

        return $next;
    }

    /**
     * apcu_cas() — compare-and-swap on integer entries only (PECL apc_cache_atomic_update_long).
     */
    public static function cas(string $key, int $old, int $new): bool
    {
        $success = false;
        $current = self::fetch($key, $success);
        if (!$success || null === $current || Variable::TYPE_INTEGER !== $current->type) {
            return false;
        }
        if ($current->toInt() !== $old) {
            return false;
        }
        $slot = new Variable();
        $slot->int($new);
        $expires = self::$store[$key]['expires'] ?? 0;
        self::$store[$key] = ['value' => $slot, 'expires' => $expires];

        return true;
    }

    /**
     * Minimal apcu_key_info() shape (PECL apc_cache_stat).
     *
     * @return array<string, mixed>|null
     */
    public static function keyInfo(string $key): ?array
    {
        if (!self::exists($key)) {
            return null;
        }
        $entry = self::$store[$key];
        $ttl = $entry['expires'] > 0 ? \max(0, $entry['expires'] - \time()) : 0;

        return [
            'hits' => 0,
            'access_time' => 0,
            'mtime' => 0,
            'creation_time' => 0,
            'deletion_time' => 0,
            'ttl' => $ttl,
            'mem_size' => 0,
        ];
    }

    /**
     * Synthetic SMA info for the in-process cache (no SHM; PECL-compatible keys).
     *
     * @return array<string, mixed>
     */
    public static function smaInfo(bool $limited = false): array
    {
        $info = [
            'num_seg' => 1,
            'seg_size' => 32 * 1024 * 1024,
            'avail_mem' => 32 * 1024 * 1024,
        ];
        if (!$limited) {
            $info['block_lists'] = [[
                ['size' => 32 * 1024 * 1024, 'offset' => 0],
            ]];
        }

        return $info;
    }

    public static function enabled(): bool
    {
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
     * Active user-cache keys after TTL expiry (APCUIterator; #27877).
     *
     * @return list<string>
     */
    public static function listKeys(): array
    {
        self::expireStale();

        return \array_keys(self::$store);
    }

    /**
     * Snapshot of one active entry for APCUIterator::current() formatting (#27877).
     *
     * @return array{value: Variable, expires: int}|null
     */
    public static function entrySnapshot(string $key): ?array
    {
        if (!self::exists($key)) {
            return null;
        }
        $entry = self::$store[$key];
        $value = new Variable();
        $value->duplicateFrom($entry['value']);

        return ['value' => $value, 'expires' => $entry['expires']];
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
