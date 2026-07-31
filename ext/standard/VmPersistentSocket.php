<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Persistent TCP socket registry for pfsockopen() (#3384, #8107, #8533).
 *
 * Connects via {@see VmStreamSocketNative} — no host {@see \pfsockopen()} delegation.
 * php-src: ext/standard/fsock.c — php_stream_popen persistent list keyed by proto://host:port
 */
final class VmPersistentSocket
{
    /** @var array<string, int> */
    private static array $cache = [];

    /** @var array<int, string> fd stream handle => cache key */
    private static array $handleKeys = [];

    /**
     * Compose the stream xport URI for fsockopen/pfsockopen (#25779).
     *
     * php-src fsock.c — php_fsockopen_format_host_port: when port > 0, append ":port"
     * to $hostname as-is (hostname may already include udp:// / tcp:// / unix://).
     * Bare hosts without a scheme get an explicit tcp:// prefix for the VM stream layer.
     */
    public static function remoteUri(string $hostname, int $port): string
    {
        $hasScheme = 1 === \preg_match('#^[a-z][a-z0-9+.-]*://#i', $hostname);

        // php-src: if (port > 0) hostname = host ":" port  (empty prefix)
        if ($port > 0) {
            if ($hasScheme) {
                return $hostname.':'.$port;
            }

            return 'tcp://'.$hostname.':'.$port;
        }

        if ($hasScheme) {
            return $hostname;
        }

        return 'tcp://'.$hostname;
    }

    /**
     * @return array{0: int|false, 1: int, 2: string, 3: ?int}
     */
    public static function open(string $hostname, int $port, ?float $timeout = null): array
    {
        $key = self::persistentKey($hostname, $port);
        $cached = self::$cache[$key] ?? null;
        if (\is_int($cached) && VmPhpFdStream::isValidHandle($cached)) {
            return [$cached, 0, '', VmPhpFdStream::fdForHandle($cached)];
        }
        if (null !== $cached) {
            unset(self::$cache[$key]);
        }

        $remote = self::remoteUri($hostname, $port);
        $connectTimeout = null === $timeout ? 60.0 : $timeout;
        [$handle, $errno, $errstr, $socketFd] = VmStreamSocketNative::client(
            $remote,
            $connectTimeout,
            \STREAM_CLIENT_CONNECT
        );
        if (false === $handle) {
            return [false, $errno, $errstr, null];
        }

        self::$cache[$key] = $handle;
        self::$handleKeys[$handle] = $key;

        return [$handle, 0, '', $socketFd];
    }

    public static function forgetHandle(int $handle): void
    {
        $key = self::$handleKeys[$handle] ?? null;
        if (null === $key) {
            return;
        }
        unset(self::$handleKeys[$handle]);
        if ((self::$cache[$key] ?? null) === $handle) {
            unset(self::$cache[$key]);
        }
    }

    /**
     * Drop persistent entry when the underlying stream is closed (VmFs refcount zero).
     *
     * @param resource|object $resource
     */
    public static function forgetResource($resource): void
    {
        unset($resource);
    }

    private static function persistentKey(string $hostname, int $port): string
    {
        return $hostname.':'.$port;
    }
}
