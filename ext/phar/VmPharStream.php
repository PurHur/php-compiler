<?php

declare(strict_types=1);

namespace PHPCompiler\ext\phar;

use PHPCompiler\ext\standard\VmFs;
use PHPCompiler\ext\standard\VmFsOpenNative;
use PHPCompiler\ext\standard\VmPhpMemoryStream;
use PHPCompiler\ext\standard\VmStatPath;
use PHPCompiler\ext\standard\VmZlib;

/**
 * phar:// stream reads + mapPhar/intercept state — php-src ext/phar/stream.c / phar_object.c (#21338).
 */
final class VmPharStream
{
    private static bool $interceptFileFuncs = false;

    private static string $mappedArchivePath = '';

    private static string $mappedAlias = '';

    /** @var array<string, array{files: array<string, string>, dirs: array<string, true>}> */
    private static array $entryCache = [];

    public static function interceptEnabled(): bool
    {
        return self::$interceptFileFuncs;
    }

    public static function enableInterceptFileFuncs(): void
    {
        self::$interceptFileFuncs = true;
    }

    /**
     * Rewrite plain relative paths when intercept is on and a phar is mapped (php-src phar_intercept).
     */
    public static function rewriteInterceptedPath(string $path): string
    {
        if (!self::$interceptFileFuncs || '' === self::$mappedAlias) {
            return $path;
        }
        if (\str_contains($path, '://')) {
            return $path;
        }
        if ('' !== $path && ('/' === $path[0] || ('\\' === $path[0] && \strlen($path) > 2 && ':' === $path[2]))) {
            return $path;
        }

        return 'phar://'.self::$mappedAlias.'/'.\ltrim(\str_replace('\\', '/', $path), '/');
    }

    /**
     * php-src zim_Phar_mapPhar — register executing archive alias (#21338).
     */
    public static function mapPhar(?string $alias, int $dataoffset, string $executedPath): bool
    {
        if ($dataoffset < 0) {
            throw new \UnexpectedValueException('data offset cannot be negative');
        }
        $archivePath = self::archivePathFromExecutedScript($executedPath);
        if ('' === $archivePath) {
            throw new \PharException('mapPhar() must be called from within a phar archive');
        }
        VmPharArchive::assertReadablePharFilePublic($archivePath);
        if (null === $alias || '' === $alias) {
            $base = \basename($archivePath);
            if (str_ends_with(strtolower($base), '.phar')) {
                $alias = substr($base, 0, -5);
            } else {
                $alias = $base;
            }
        }
        VmPharArchive::registerAlias($alias, $archivePath);
        self::$mappedArchivePath = $archivePath;
        self::$mappedAlias = $alias;
        unset(self::$entryCache[$archivePath]);

        return true;
    }

    public static function isPharUri(string $path): bool
    {
        return str_starts_with($path, 'phar://');
    }

    /**
     * @return string|false
     */
    public static function readContents(string $uri)
    {
        $parsed = self::parseUri($uri);
        if (null === $parsed) {
            return false;
        }
        [$archive, $entry] = $parsed;
        $entries = self::loadEntries($archive);
        if (null === $entries) {
            return false;
        }
        $entry = VmPharArchive::normalizeEntryNamePublic($entry);
        if ('' === $entry) {
            return false;
        }
        if (isset($entries['dirs'][$entry])) {
            return false;
        }
        if (!isset($entries['files'][$entry])) {
            return false;
        }

        return $entries['files'][$entry];
    }

    /**
     * @return int|false stream handle
     */
    public static function open(string $uri, string $mode)
    {
        if (!self::isReadableMode($mode)) {
            return VmFs::allocateFailedStreamHandle();
        }
        $data = self::readContents($uri);
        if (false === $data) {
            return false;
        }

        return VmPhpMemoryStream::openWithBuffer($uri, $data, 'rb');
    }

    /**
     * @return array{0: string, 1: string}|null archive path + internal entry
     */
    private static function parseUri(string $uri): ?array
    {
        if (!str_starts_with($uri, 'phar://')) {
            return null;
        }
        $rest = substr($uri, 7);
        if ('' === $rest) {
            return null;
        }
        if ('/' === $rest[0]) {
            $archivePath = VmPhar::runningPath('phar://'.$rest, false);
            if ('' === $archivePath) {
                return null;
            }
            $entry = substr($rest, \strlen($archivePath) + 1);
            $entry = \ltrim($entry, '/');

            return [$archivePath, $entry];
        }
        $slash = \strpos($rest, '/');
        if (false === $slash) {
            $alias = $rest;
            $entry = '';
        } else {
            $alias = substr($rest, 0, $slash);
            $entry = substr($rest, $slash + 1);
        }
        $archivePath = VmPharArchive::resolveAliasPath($alias);
        if (null === $archivePath) {
            if (VmStatPath::isFile($alias)) {
                $archivePath = $alias;
            } else {
                return null;
            }
        }

        return [$archivePath, $entry];
    }

    /**
     * @return array{files: array<string, string>, dirs: array<string, true>}|null
     */
    private static function loadEntries(string $archivePath): ?array
    {
        $archivePath = \str_replace('\\', '/', $archivePath);
        if (isset(self::$entryCache[$archivePath])) {
            return self::$entryCache[$archivePath];
        }
        $live = VmPharArchive::liveEntriesForPath($archivePath);
        if (null !== $live) {
            self::$entryCache[$archivePath] = $live;

            return $live;
        }
        if (!VmStatPath::isFile($archivePath)) {
            return null;
        }
        $binary = self::readRawFile($archivePath);
        if (false === $binary) {
            return null;
        }
        if (\strlen($binary) >= 2 && "\x1f" === $binary[0] && "\x8b" === $binary[1]) {
            $decoded = VmZlib::gzdecode($binary);
            if (false === $decoded) {
                return null;
            }
            $binary = $decoded;
        }
        if (!\str_contains($binary, '__HALT_COMPILER()')) {
            return null;
        }
        [, $payload] = VmPharArchive::splitStubPublic($binary);
        $entries = VmPharTar::readArchiveEntries($payload);
        self::$entryCache[$archivePath] = $entries;

        return $entries;
    }

    /** @return string|false */
    private static function readRawFile(string $path)
    {
        if (VmFsOpenNative::available()) {
            $handle = VmFsOpenNative::open($path, 'rb');
            if (false === $handle) {
                return false;
            }
            $data = VmFs::streamGetContents($handle);
            VmFs::fclose($handle);

            return $data;
        }

        return @\file_get_contents($path);
    }

    private static function archivePathFromExecutedScript(string $scriptPath): string
    {
        if ('' === $scriptPath) {
            return '';
        }
        if (str_starts_with($scriptPath, 'phar://')) {
            return VmPhar::runningPath($scriptPath, false);
        }
        $path = \str_replace('\\', '/', $scriptPath);
        $pos = stripos($path, '.phar');
        if (false === $pos) {
            return '';
        }
        $archive = substr($path, 0, $pos + 5);
        if (!VmStatPath::isFile($archive)) {
            return '';
        }

        return $archive;
    }

    private static function isReadableMode(string $mode): bool
    {
        return '' !== $mode && !\str_contains($mode, 'w') && !\str_contains($mode, 'a') && !\str_contains($mode, '+');
    }

    /** @internal PHPUnit */
    public static function resetForTests(): void
    {
        self::$interceptFileFuncs = false;
        self::$mappedArchivePath = '';
        self::$mappedAlias = '';
        self::$entryCache = [];
    }
}
