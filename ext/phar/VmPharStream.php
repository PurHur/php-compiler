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
    public const MUNG_PHP_SELF = 1;

    public const MUNG_REQUEST_URI = 2;

    public const MUNG_SCRIPT_NAME = 4;

    public const MUNG_SCRIPT_FILENAME = 8;

    private static bool $interceptFileFuncs = false;

    private static string $mappedArchivePath = '';

    private static string $mappedAlias = '';

    /** @var array<string, array<string, string>> archive path → internal entry → external path (#21327) */
    private static array $mounts = [];

    private static int $serverMungList = 0;

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
            $mounted = self::readMountedFile($archive, $entry);
            if (false !== $mounted) {
                return $mounted;
            }

            return false;
        }

        return $entries['files'][$entry];
    }

    /**
     * Phar::mount() — map internal phar path to external filesystem path (#21327, phar_object.c).
     */
    public static function mount(string $internalPath, string $externalPath, string $executedScript): void
    {
        $internalPath = \str_replace('\\', '/', $internalPath);
        $externalPath = \str_replace('\\', '/', $externalPath);
        $executedScript = \str_replace('\\', '/', $executedScript);

        if (str_starts_with($executedScript, 'phar://') && str_starts_with($internalPath, 'phar://')) {
            throw new \PharException(
                'Can only mount internal paths within a phar archive, use a relative path instead of "'.$internalPath.'"'
            );
        }

        $archivePath = '';
        $entryPath = $internalPath;
        if (str_starts_with($internalPath, 'phar://')) {
            $parsed = self::parseUri($internalPath);
            if (null === $parsed) {
                throw new \PharException('Mounting of '.$internalPath.' to '.$externalPath.' failed');
            }
            [$archivePath, $entryPath] = $parsed;
        } else {
            $archivePath = self::archivePathFromExecutedScript($executedScript);
            if ('' === $archivePath && '' !== $executedScript && VmStatPath::isFile($executedScript)) {
                $archivePath = $executedScript;
            }
            if ('' === $archivePath) {
                throw new \PharException('Mounting of '.$internalPath.' to '.$externalPath.' failed');
            }
        }

        $entryPath = VmPharArchive::normalizeEntryNamePublic($entryPath);
        if (!VmStatPath::isFile($externalPath) && !VmStatPath::isDir($externalPath)) {
            throw new \PharException(
                'Mounting of '.$entryPath.' to '.$externalPath.' within phar '.$archivePath.' failed'
            );
        }

        self::$mounts[$archivePath][$entryPath] = $externalPath;
        unset(self::$entryCache[$archivePath]);
    }

    /**
     * Phar::mungServer() — register $_SERVER keys to rewrite for web phars (#21327).
     *
     * @param list<string> $variables
     */
    public static function mungServer(array $variables): void
    {
        if ([] === $variables) {
            throw new \PharException(
                'No values passed to Phar::mungServer(), expecting an array of any of these strings: PHP_SELF, REQUEST_URI, SCRIPT_FILENAME, SCRIPT_NAME'
            );
        }
        if (\count($variables) > 4) {
            throw new \PharException(
                'Too many values passed to Phar::mungServer(), expecting an array of any of these strings: PHP_SELF, REQUEST_URI, SCRIPT_FILENAME, SCRIPT_NAME'
            );
        }

        foreach ($variables as $name) {
            switch ($name) {
                case 'PHP_SELF':
                    self::$serverMungList |= self::MUNG_PHP_SELF;
                    break;
                case 'REQUEST_URI':
                    self::$serverMungList |= self::MUNG_REQUEST_URI;
                    break;
                case 'SCRIPT_NAME':
                    self::$serverMungList |= self::MUNG_SCRIPT_NAME;
                    break;
                case 'SCRIPT_FILENAME':
                    self::$serverMungList |= self::MUNG_SCRIPT_FILENAME;
                    break;
                default:
                    throw new \PharException(
                        'Invalid value passed to Phar::mungServer(), expecting an array of any of these strings: PHP_SELF, REQUEST_URI, SCRIPT_FILENAME, SCRIPT_NAME'
                    );
            }
        }
    }

    public static function serverMungList(): int
    {
        return self::$serverMungList;
    }

    /**
     * Phar::webPhar() — mapPhar + web front-controller entry (#21327, phar_object.c).
     *
     * Non-web SAPI returns after registering the archive (php-src early exit).
     */
    public static function webPhar(
        ?string $alias,
        ?string $index,
        ?string $fileNotFoundScript,
        array $mimeTypes,
        ?callable $rewrite,
        string $scriptPath,
        ?string $requestMethod
    ): void {
        unset($fileNotFoundScript, $mimeTypes, $rewrite);

        self::mapPhar($alias, 0, $scriptPath);

        $method = null !== $requestMethod ? \strtoupper($requestMethod) : '';
        $webMethods = ['GET', 'POST', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH', 'PUT'];
        if ('' === $method || !\in_array($method, $webMethods, true)) {
            return;
        }

        // Full MIME/header dispatch is php-src phar_file_action; CLI/compliance stops after mapPhar.
        unset($index);
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

    /** @return string|false */
    private static function readMountedFile(string $archivePath, string $entry)
    {
        $archivePath = \str_replace('\\', '/', $archivePath);
        if (!isset(self::$mounts[$archivePath][$entry])) {
            return false;
        }
        $external = self::$mounts[$archivePath][$entry];
        if (VmStatPath::isDir($external)) {
            return false;
        }
        if (VmFsOpenNative::available()) {
            $handle = VmFsOpenNative::open($external, 'rb');
            if (false === $handle) {
                return false;
            }
            $data = VmFs::streamGetContents($handle);
            VmFs::fclose($handle);

            return false !== $data ? $data : false;
        }

        $data = @\file_get_contents($external);

        return false !== $data ? $data : false;
    }

    /** @internal PHPUnit */
    public static function resetForTests(): void
    {
        self::$interceptFileFuncs = false;
        self::$mappedArchivePath = '';
        self::$mappedAlias = '';
        self::$mounts = [];
        self::$serverMungList = 0;
        self::$entryCache = [];
    }
}
