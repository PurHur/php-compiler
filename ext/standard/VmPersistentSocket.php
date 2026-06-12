<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Persistent TCP socket registry for pfsockopen() (#3384, #8107).
 *
 * Connects via {@see VmStreamSocketNative} — no host {@see \pfsockopen()} delegation.
 * php-src: ext/standard/fsock.c — php_stream_popen persistent list keyed by proto://host:port
 */
final class VmPersistentSocket
{
    /** @var array<string, resource> */
    private static array $cache = [];

    /** @var array<int, string> spl_object_id(resource) => cache key */
    private static array $resourceKeys = [];

    public static function remoteUri(string $hostname, int $port): string
    {
        if ($port >= 0) {
            return 'tcp://'.$hostname.':'.$port;
        }

        return 'tcp://'.$hostname;
    }

    /**
     * @return array{0: resource|false, 1: int, 2: string, 3: ?int}
     */
    public static function open(string $hostname, int $port, ?float $timeout = null): array
    {
        $key = self::persistentKey($hostname, $port);
        $cached = self::$cache[$key] ?? null;
        if (\is_resource($cached)) {
            return [$cached, 0, '', null];
        }
        if (null !== $cached) {
            unset(self::$cache[$key]);
        }

        $remote = self::remoteUri($hostname, $port);
        $connectTimeout = null === $timeout ? 60.0 : $timeout;
        [$stream, $errno, $errstr, $socketFd] = VmStreamSocketNative::client(
            $remote,
            $connectTimeout,
            \STREAM_CLIENT_CONNECT
        );
        if (false === $stream) {
            return [false, $errno, $errstr, null];
        }

        self::$cache[$key] = $stream;
        self::$resourceKeys[self::resourceKey($stream)] = $key;

        return [$stream, 0, '', $socketFd];
    }

    /**
     * Drop persistent entry when the underlying stream is closed (VmFs refcount zero).
     *
     * @param resource|object $resource
     */
    public static function forgetResource($resource): void
    {
        $id = self::resourceKey($resource);
        $key = self::$resourceKeys[$id] ?? null;
        if (null === $key) {
            return;
        }
        unset(self::$resourceKeys[$id]);
        if ((self::$cache[$key] ?? null) === $resource) {
            unset(self::$cache[$key]);
        }
    }

    private static function persistentKey(string $hostname, int $port): string
    {
        return $hostname.':'.$port;
    }

    /**
     * @param resource|object $resource
     */
    private static function resourceKey($resource): int
    {
        return \is_object($resource) ? \spl_object_id($resource) : (int) $resource;
    }
}
