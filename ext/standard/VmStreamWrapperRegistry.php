<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * User stream wrapper protocol registry (php-src main/streams; issues #3383, #6818).
 *
 * PHP-in-PHP: no phpc_stream.c wrapper table — custom protocols live here.
 */
final class VmStreamWrapperRegistry
{
    /** @var list<string> Built-in schemes always reported by stream_get_wrappers(). */
    private const BUILTIN_PROTOCOLS = [
        'file',
        'http',
        'https',
        'ftp',
        'ftps',
        'php',
        'compress.zlib',
        'data',
        'glob',
        'phar',
    ];

    /** @var array<string, string> lowercase protocol => wrapper class name */
    private static array $custom = [];

    /** @var array<string, list<string|null>> protocol => stack of prior class names (null = removed) */
    private static array $restoreStack = [];

    public static function register(string $protocol, string $className): bool
    {
        $key = self::normalizeProtocol($protocol);
        if ('' === $key || isset(self::$custom[$key])) {
            return false;
        }
        self::$custom[$key] = $className;

        return true;
    }

    public static function unregister(string $protocol): bool
    {
        $key = self::normalizeProtocol($protocol);
        if ('' === $key || !isset(self::$custom[$key])) {
            return false;
        }
        self::$restoreStack[$key][] = self::$custom[$key];
        unset(self::$custom[$key]);

        return true;
    }

    public static function restore(string $protocol): bool
    {
        $key = self::normalizeProtocol($protocol);
        if ('' === $key || !isset(self::$restoreStack[$key]) || [] === self::$restoreStack[$key]) {
            return false;
        }
        $prior = \array_pop(self::$restoreStack[$key]);
        if ([] === self::$restoreStack[$key]) {
            unset(self::$restoreStack[$key]);
        }
        if (null === $prior) {
            unset(self::$custom[$key]);

            return true;
        }
        if (isset(self::$custom[$key])) {
            return false;
        }
        self::$custom[$key] = $prior;

        return true;
    }

    /** @return list<string> */
    public static function getWrappers(): array
    {
        $all = self::BUILTIN_PROTOCOLS;
        foreach (\array_keys(self::$custom) as $protocol) {
            $all[] = $protocol;
        }
        \sort($all);

        return $all;
    }

    public static function lookupClass(string $protocol): ?string
    {
        $key = self::normalizeProtocol($protocol);

        return self::$custom[$key] ?? null;
    }

    public static function parseProtocol(string $uri): ?string
    {
        if (!\str_contains($uri, '://')) {
            return null;
        }
        $protocol = \strtolower((string) \strstr($uri, '://', true));
        if ('' === $protocol) {
            return null;
        }

        return $protocol;
    }

    public static function isCustomProtocol(string $uri): bool
    {
        $protocol = self::parseProtocol($uri);
        if (null === $protocol) {
            return false;
        }

        return isset(self::$custom[$protocol]);
    }

    private static function normalizeProtocol(string $protocol): string
    {
        $protocol = \strtolower(\trim($protocol));
        if ('' === $protocol || !\preg_match('/^[a-z][a-z0-9+.-]*$/', $protocol)) {
            return '';
        }

        return $protocol;
    }

    /** @internal PHPUnit isolation */
    public static function resetForTests(): void
    {
        self::$custom = [];
        self::$restoreStack = [];
    }
}
