<?php

declare(strict_types=1);

namespace PHPCompiler\ext\memcached;

/**
 * Pure-PHP memcached ASCII client over host TCP streams (PECL php-memcached subset; #6099).
 *
 * No libmemcached / runtime/*.c — connect/set/get/delete via stream_socket_client + ASCII framing.
 */
final class VmMemcachedNative
{
    /**
     * @return resource
     *
     * @throws \RuntimeException
     */
    public static function connect(string $host, int $port, float $timeout = 1.0)
    {
        $remote = \sprintf('tcp://%s:%d', $host, $port);
        $errno = 0;
        $errstr = '';
        $socket = @\stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout > 0.0 ? $timeout : 1.0,
            \STREAM_CLIENT_CONNECT
        );
        if (false === $socket) {
            $message = '' !== $errstr ? $errstr : 'Connection failure';
            throw new \RuntimeException($message, (int) $errno);
        }
        \stream_set_timeout($socket, (int) \max(1, (int) \ceil($timeout > 0.0 ? $timeout : 1.0)));

        return $socket;
    }

    /** @param resource $socket */
    public static function close($socket): void
    {
        if (\is_resource($socket)) {
            @\fclose($socket);
        }
    }

    /**
     * @param resource $socket
     *
     * @return array{ok: bool, code: int}
     */
    public static function set($socket, string $key, string $value, int $expiration): array
    {
        $bytes = \strlen($value);
        $cmd = \sprintf("set %s 0 %d %d\r\n%s\r\n", $key, $expiration, $bytes, $value);
        if (!self::writeAll($socket, $cmd)) {
            return ['ok' => false, 'code' => MemcachedConstants::RES_WRITE_FAILURE];
        }
        $line = self::readLine($socket);
        if (null === $line) {
            return ['ok' => false, 'code' => MemcachedConstants::RES_READ_FAILURE];
        }
        if ('STORED' === $line) {
            return ['ok' => true, 'code' => MemcachedConstants::RES_SUCCESS];
        }
        if ('NOT_STORED' === $line) {
            return ['ok' => false, 'code' => MemcachedConstants::RES_NOTSTORED];
        }

        return ['ok' => false, 'code' => MemcachedConstants::RES_FAILURE];
    }

    /**
     * @param resource $socket
     *
     * @return array{value: string|false, code: int}
     */
    public static function get($socket, string $key): array
    {
        $cmd = \sprintf("get %s\r\n", $key);
        if (!self::writeAll($socket, $cmd)) {
            return ['value' => false, 'code' => MemcachedConstants::RES_WRITE_FAILURE];
        }
        $line = self::readLine($socket);
        if (null === $line) {
            return ['value' => false, 'code' => MemcachedConstants::RES_READ_FAILURE];
        }
        if ('END' === $line) {
            return ['value' => false, 'code' => MemcachedConstants::RES_NOTFOUND];
        }
        if (!\str_starts_with($line, 'VALUE ')) {
            return ['value' => false, 'code' => MemcachedConstants::RES_FAILURE];
        }
        $parts = \explode(' ', $line);
        $bytes = isset($parts[3]) ? (int) $parts[3] : 0;
        $payload = self::readExact($socket, $bytes);
        if (null === $payload) {
            return ['value' => false, 'code' => MemcachedConstants::RES_READ_FAILURE];
        }
        // Trailing CRLF after value
        self::readExact($socket, 2);
        $end = self::readLine($socket);
        if ('END' !== $end) {
            // Drain until END if server sent extra
            while (null !== $end && 'END' !== $end) {
                $end = self::readLine($socket);
            }
        }

        return ['value' => $payload, 'code' => MemcachedConstants::RES_SUCCESS];
    }

    /**
     * @param resource $socket
     *
     * @return array{ok: bool, code: int}
     */
    public static function delete($socket, string $key): array
    {
        $cmd = \sprintf("delete %s\r\n", $key);
        if (!self::writeAll($socket, $cmd)) {
            return ['ok' => false, 'code' => MemcachedConstants::RES_WRITE_FAILURE];
        }
        $line = self::readLine($socket);
        if (null === $line) {
            return ['ok' => false, 'code' => MemcachedConstants::RES_READ_FAILURE];
        }
        if ('DELETED' === $line) {
            return ['ok' => true, 'code' => MemcachedConstants::RES_SUCCESS];
        }
        if ('NOT_FOUND' === $line) {
            return ['ok' => false, 'code' => MemcachedConstants::RES_NOTFOUND];
        }

        return ['ok' => false, 'code' => MemcachedConstants::RES_FAILURE];
    }

    /** @param resource $socket */
    private static function writeAll($socket, string $data): bool
    {
        $len = \strlen($data);
        $written = 0;
        while ($written < $len) {
            $n = @\fwrite($socket, \substr($data, $written));
            if (false === $n || 0 === $n) {
                return false;
            }
            $written += $n;
        }

        return true;
    }

    /** @param resource $socket */
    private static function readLine($socket): ?string
    {
        $line = @\fgets($socket);
        if (false === $line) {
            return null;
        }

        return \rtrim($line, "\r\n");
    }

    /** @param resource $socket */
    private static function readExact($socket, int $bytes): ?string
    {
        if ($bytes <= 0) {
            return '';
        }
        $buf = '';
        while (\strlen($buf) < $bytes) {
            $chunk = @\fread($socket, $bytes - \strlen($buf));
            if (false === $chunk || '' === $chunk) {
                return null;
            }
            $buf .= $chunk;
        }

        return $buf;
    }
}
