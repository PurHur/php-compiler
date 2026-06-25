<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Per-request realpath cache for VM builtins (issue #3463).
 *
 * php-src: ext/standard/url.c — realpath_cache_get(), realpath_cache_size()
 */
final class VmRealpathCache
{
    /** Default TTL when ini realpath_cache_ttl is unavailable (php-src default 120). */
    private const DEFAULT_TTL = 120;

    /** Approximate struct overhead per entry (Zend realpath cache node). */
    private const ENTRY_OVERHEAD = 64;

    /**
     * @var array<string, array{
     *   key: float,
     *   is_dir: bool,
     *   realpath: string,
     *   expires: int
     * }>
     */
    private static array $entries = [];

    private static int $sizeBytes = 0;

    public static function record(string $path, string $resolved): void
    {
        if ('' === $resolved) {
            return;
        }

        if (str_starts_with($path, '/')) {
            self::recordEntry($path, $resolved);
        }
        self::recordResolvedPrefixes($resolved);
    }

    private static function recordEntry(string $path, string $resolved, ?bool $isDir = null): void
    {
        if ('' === $path || '' === $resolved) {
            return;
        }

        if (isset(self::$entries[$path])) {
            self::$sizeBytes -= self::entryBytes($path, self::$entries[$path]['realpath']);
        }

        $entry = [
            'key' => self::hashKey($path),
            'is_dir' => $isDir ?? VmStatPath::isDir($resolved),
            'realpath' => $resolved,
            'expires' => time() + self::DEFAULT_TTL,
        ];
        self::$entries[$path] = $entry;
        self::$sizeBytes += self::entryBytes($path, $resolved);
    }

    /** php-src realpath_cache_add — one entry per resolved path prefix walked (#11347). */
    private static function recordResolvedPrefixes(string $resolved): void
    {
        if (!str_starts_with($resolved, '/')) {
            return;
        }

        $isFile = VmStatPath::isFile($resolved);
        $parts = explode('/', trim($resolved, '/'));
        if ([] === $parts) {
            return;
        }

        $built = '';
        $lastIndex = \count($parts) - 1;
        foreach ($parts as $index => $part) {
            $built .= '/'.$part;
            $isDir = $index < $lastIndex || !$isFile;
            self::recordEntry($built, $built, $isDir);
        }
    }

    public static function size(): int
    {
        return max(0, self::$sizeBytes);
    }

    public static function get(): HashTable
    {
        $out = new HashTable();
        foreach (self::$entries as $originalPath => $entry) {
            $entryHt = new HashTable();

            $keyVar = new Variable();
            $keyVar->float($entry['key']);
            $entryHt->add('key', $keyVar);

            $isDirVar = new Variable();
            $isDirVar->bool($entry['is_dir']);
            $entryHt->add('is_dir', $isDirVar);

            $realpathVar = new Variable();
            $realpathVar->string($entry['realpath']);
            $entryHt->add('realpath', $realpathVar);

            $expiresVar = new Variable();
            $expiresVar->int($entry['expires']);
            $entryHt->add('expires', $expiresVar);

            $wrapped = new Variable();
            $wrapped->array($entryHt);
            $out->add($originalPath, $wrapped);
        }

        return $out;
    }

    public static function clear(): void
    {
        self::$entries = [];
        self::$sizeBytes = 0;
    }

    public static function remove(string $path): void
    {
        if (!isset(self::$entries[$path])) {
            return;
        }
        self::$sizeBytes -= self::entryBytes($path, self::$entries[$path]['realpath']);
        unset(self::$entries[$path]);
    }

    public static function reset(): void
    {
        self::clear();
    }

    private static function hashKey(string $path): float
    {
        $crc = crc32($path);
        if ($crc < 0) {
            $crc = $crc + 0x100000000;
        }

        return (float) $crc;
    }

    private static function entryBytes(string $original, string $resolved): int
    {
        return strlen($original) + strlen($resolved) + self::ENTRY_OVERHEAD;
    }
}
