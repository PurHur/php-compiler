<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\VM\Variable;

/**
 * Procedural zip API — zip_open/read/close + zip_entry_* (php-src ext/zip/php_zip.c; #6370).
 *
 * Pure-PHP over {@see ZipEngine}; resource handles via {@see VmFs} zip placeholders.
 */
final class VmZipProcedural
{
    /** @var array<int, array{path: string, entries: list<array{name: string, data: string, crc: int, size: int, comp_size?: int, comp_method?: int}>, index: int}> */
    private static array $archives = [];

    /** @var array<int, array{archive: int, entryIndex: int, pos: int, open: bool}> */
    private static array $entries = [];

    public static function zipOpen(string $filename): int|false
    {
        if (!is_file($filename)) {
            return false;
        }
        $read = ZipEngine::readArchive($filename);
        if (!$read['ok']) {
            return false;
        }
        $handle = VmFs::adoptZipArchivePlaceholder($filename);
        if (false === $handle) {
            return false;
        }
        self::$archives[$handle] = [
            'path' => $filename,
            'entries' => $read['entries'],
            'index' => 0,
        ];

        return $handle;
    }

    public static function zipClose(int $handle): bool
    {
        if (!self::isArchiveHandle($handle)) {
            return false;
        }
        foreach (self::$entries as $entryHandle => $entry) {
            if ($entry['archive'] === $handle) {
                unset(self::$entries[$entryHandle]);
                VmFs::releaseZipEntryPlaceholder($entryHandle);
            }
        }
        unset(self::$archives[$handle]);
        VmFs::releaseZipArchivePlaceholder($handle);

        return true;
    }

    public static function zipRead(int $archiveHandle): int|false
    {
        $archive = self::$archives[$archiveHandle] ?? null;
        if (null === $archive) {
            return false;
        }
        if ($archive['index'] >= \count($archive['entries'])) {
            return false;
        }
        $entryHandle = VmFs::adoptZipEntryPlaceholder($archiveHandle);
        if (false === $entryHandle) {
            return false;
        }
        self::$entries[$entryHandle] = [
            'archive' => $archiveHandle,
            'entryIndex' => $archive['index'],
            'pos' => 0,
            'open' => false,
        ];
        ++self::$archives[$archiveHandle]['index'];

        return $entryHandle;
    }

    public static function zipEntryOpen(int $archiveHandle, int $entryHandle, string $mode = 'rb'): bool
    {
        if (!self::isArchiveHandle($archiveHandle) || !self::isEntryHandle($entryHandle)) {
            return false;
        }
        $entry = self::$entries[$entryHandle];
        if ($entry['archive'] !== $archiveHandle) {
            return false;
        }
        if ('' !== $mode && 'r' !== $mode && 'rb' !== $mode) {
            return false;
        }
        self::$entries[$entryHandle]['open'] = true;
        self::$entries[$entryHandle]['pos'] = 0;

        return true;
    }

    public static function zipEntryClose(int $entryHandle): bool
    {
        if (!self::isEntryHandle($entryHandle)) {
            return false;
        }
        self::$entries[$entryHandle]['open'] = false;
        self::$entries[$entryHandle]['pos'] = 0;

        return true;
    }

    public static function zipEntryRead(int $entryHandle, int $length = 1024): string|false
    {
        $entry = self::$entries[$entryHandle] ?? null;
        if (null === $entry || !$entry['open']) {
            return false;
        }
        if ($length < 0) {
            return false;
        }
        $payload = self::entryData($entry);
        if (null === $payload) {
            return false;
        }
        if (0 === $length) {
            return '';
        }
        $remaining = \strlen($payload) - $entry['pos'];
        if ($remaining <= 0) {
            return '';
        }
        $take = \min($length, $remaining);
        $chunk = \substr($payload, $entry['pos'], $take);
        self::$entries[$entryHandle]['pos'] += $take;

        return $chunk;
    }

    public static function zipEntryName(int $entryHandle): string|false
    {
        $entry = self::$entries[$entryHandle] ?? null;
        if (null === $entry) {
            return false;
        }
        $archive = self::$archives[$entry['archive']] ?? null;
        if (null === $archive) {
            return false;
        }
        $zipEntry = $archive['entries'][$entry['entryIndex']] ?? null;
        if (null === $zipEntry) {
            return false;
        }

        return $zipEntry['name'];
    }

    public static function zipEntryFilesize(int $entryHandle): int|false
    {
        $zipEntry = self::entryMeta($entryHandle);
        if (null === $zipEntry) {
            return false;
        }

        return (int) $zipEntry['size'];
    }

    /** Compressed size from central directory (php_zip.c zip_entry_compressedsize; #20485). */
    public static function zipEntryCompressedsize(int $entryHandle): int|false
    {
        $zipEntry = self::entryMeta($entryHandle);
        if (null === $zipEntry) {
            return false;
        }
        if (isset($zipEntry['comp_size'])) {
            return (int) $zipEntry['comp_size'];
        }

        // Stored archives write comp_size == size; fall back for in-memory rows.
        return (int) $zipEntry['size'];
    }

    /**
     * Compression method name (php_zip.c zip_entry_compressionmethod; #20485).
     *
     * @return string|false
     */
    public static function zipEntryCompressionmethod(int $entryHandle): string|false
    {
        $zipEntry = self::entryMeta($entryHandle);
        if (null === $zipEntry) {
            return false;
        }
        $method = (int) ($zipEntry['comp_method'] ?? 0);

        return match ($method) {
            0 => 'stored',
            1 => 'shrunk',
            2, 3, 4, 5 => 'reduced',
            6 => 'imploded',
            7 => 'tokenized',
            8 => 'deflated',
            9 => 'deflatedX',
            10 => 'implodedX',
            default => false,
        };
    }

    public static function isArchiveHandle(int $handle): bool
    {
        return isset(self::$archives[$handle]) && VmFs::isZipArchivePlaceholder($handle);
    }

    public static function isEntryHandle(int $handle): bool
    {
        return isset(self::$entries[$handle]) && VmFs::isZipEntryPlaceholder($handle);
    }

    public static function requireArchiveHandle(
        Variable $var,
        string $function,
        int $argNum = 1,
        string $paramName = 'zip'
    ): int {
        $var = $var->resolveIndirect();
        $handle = VmZipResourceArg::resolveHandle($var);
        if (null === $handle || !self::isArchiveHandle($handle)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type resource, %s given',
                $function,
                $argNum,
                $paramName,
                VmZipResourceArg::debugTypeName($var)
            ));
        }

        return $handle;
    }

    public static function requireEntryHandle(Variable $var, string $function, int $argNum = 1): int
    {
        $var = $var->resolveIndirect();
        $handle = VmZipResourceArg::resolveHandle($var);
        if (null === $handle || !self::isEntryHandle($handle)) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type resource, %s given',
                $function,
                $argNum,
                'zip_entry',
                VmZipResourceArg::debugTypeName($var)
            ));
        }

        return $handle;
    }

    /** @return ?string */
    private static function entryData(array $entry): ?string
    {
        $archive = self::$archives[$entry['archive']] ?? null;
        if (null === $archive) {
            return null;
        }

        return $archive['entries'][$entry['entryIndex']]['data'] ?? null;
    }

    /**
     * @return array{name: string, data: string, crc: int, size: int, comp_size?: int, comp_method?: int}|null
     */
    private static function entryMeta(int $entryHandle): ?array
    {
        $entry = self::$entries[$entryHandle] ?? null;
        if (null === $entry) {
            return null;
        }
        $archive = self::$archives[$entry['archive']] ?? null;
        if (null === $archive) {
            return null;
        }

        return $archive['entries'][$entry['entryIndex']] ?? null;
    }
}
