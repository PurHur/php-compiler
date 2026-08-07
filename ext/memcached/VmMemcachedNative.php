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

    /**
     * add — store only if key does not exist (ASCII add).
     *
     * @param resource $socket
     *
     * @return array{ok: bool, code: int}
     */
    public static function add($socket, string $key, string $value, int $expiration): array
    {
        $bytes = \strlen($value);
        $cmd = \sprintf("add %s 0 %d %d\r\n%s\r\n", $key, $expiration, $bytes, $value);
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
     * replace — store only if key exists.
     *
     * @param resource $socket
     *
     * @return array{ok: bool, code: int}
     */
    public static function replace($socket, string $key, string $value, int $expiration): array
    {
        $bytes = \strlen($value);
        $cmd = \sprintf("replace %s 0 %d %d\r\n%s\r\n", $key, $expiration, $bytes, $value);
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
     * @return array{ok: bool, code: int}
     */
    public static function append($socket, string $key, string $value): array
    {
        $bytes = \strlen($value);
        $cmd = \sprintf("append %s 0 0 %d\r\n%s\r\n", $key, $bytes, $value);
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
     * @return array{ok: bool, code: int}
     */
    public static function prepend($socket, string $key, string $value): array
    {
        $bytes = \strlen($value);
        $cmd = \sprintf("prepend %s 0 0 %d\r\n%s\r\n", $key, $bytes, $value);
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
     * @return array{value: int|false, code: int}
     */
    public static function incr($socket, string $key, int $offset): array
    {
        $cmd = \sprintf("incr %s %d\r\n", $key, $offset);
        if (!self::writeAll($socket, $cmd)) {
            return ['value' => false, 'code' => MemcachedConstants::RES_WRITE_FAILURE];
        }
        $line = self::readLine($socket);
        if (null === $line) {
            return ['value' => false, 'code' => MemcachedConstants::RES_READ_FAILURE];
        }
        if ('NOT_FOUND' === $line) {
            return ['value' => false, 'code' => MemcachedConstants::RES_NOTFOUND];
        }
        if (\ctype_digit($line)) {
            return ['value' => (int) $line, 'code' => MemcachedConstants::RES_SUCCESS];
        }

        return ['value' => false, 'code' => MemcachedConstants::RES_FAILURE];
    }

    /**
     * @param resource $socket
     *
     * @return array{value: int|false, code: int}
     */
    public static function decr($socket, string $key, int $offset): array
    {
        $cmd = \sprintf("decr %s %d\r\n", $key, $offset);
        if (!self::writeAll($socket, $cmd)) {
            return ['value' => false, 'code' => MemcachedConstants::RES_WRITE_FAILURE];
        }
        $line = self::readLine($socket);
        if (null === $line) {
            return ['value' => false, 'code' => MemcachedConstants::RES_READ_FAILURE];
        }
        if ('NOT_FOUND' === $line) {
            return ['value' => false, 'code' => MemcachedConstants::RES_NOTFOUND];
        }
        if (\ctype_digit($line)) {
            return ['value' => (int) $line, 'code' => MemcachedConstants::RES_SUCCESS];
        }

        return ['value' => false, 'code' => MemcachedConstants::RES_FAILURE];
    }

    /**
     * @param resource $socket
     *
     * @return array{ok: bool, code: int}
     */
    public static function flush($socket, int $delay = 0): array
    {
        $cmd = $delay > 0 ? \sprintf("flush_all %d\r\n", $delay) : "flush_all\r\n";
        if (!self::writeAll($socket, $cmd)) {
            return ['ok' => false, 'code' => MemcachedConstants::RES_WRITE_FAILURE];
        }
        $line = self::readLine($socket);
        if (null === $line) {
            return ['ok' => false, 'code' => MemcachedConstants::RES_READ_FAILURE];
        }
        if ('OK' === $line) {
            return ['ok' => true, 'code' => MemcachedConstants::RES_SUCCESS];
        }

        return ['ok' => false, 'code' => MemcachedConstants::RES_FAILURE];
    }

    /**
     * Multi-get via ASCII `get k1 k2 …`.
     *
     * @param resource $socket
     * @param list<string> $keys
     *
     * @return array{values: array<string, string>, code: int}
     */
    public static function getMulti($socket, array $keys): array
    {
        if ([] === $keys) {
            return ['values' => [], 'code' => MemcachedConstants::RES_SUCCESS];
        }
        $cmd = 'get '.\implode(' ', $keys)."\r\n";
        if (!self::writeAll($socket, $cmd)) {
            return ['values' => [], 'code' => MemcachedConstants::RES_WRITE_FAILURE];
        }
        $values = [];
        while (true) {
            $line = self::readLine($socket);
            if (null === $line) {
                return ['values' => $values, 'code' => MemcachedConstants::RES_READ_FAILURE];
            }
            if ('END' === $line) {
                break;
            }
            if (!\str_starts_with($line, 'VALUE ')) {
                return ['values' => $values, 'code' => MemcachedConstants::RES_FAILURE];
            }
            $parts = \explode(' ', $line);
            $key = $parts[1] ?? '';
            $bytes = isset($parts[3]) ? (int) $parts[3] : 0;
            $payload = self::readExact($socket, $bytes);
            if (null === $payload) {
                return ['values' => $values, 'code' => MemcachedConstants::RES_READ_FAILURE];
            }
            self::readExact($socket, 2);
            $values[$key] = $payload;
        }

        return ['values' => $values, 'code' => MemcachedConstants::RES_SUCCESS];
    }

    /**
     * cas — compare-and-swap (ASCII cas).
     *
     * @param resource $socket
     *
     * @return array{ok: bool, code: int}
     */
    public static function cas($socket, int $casToken, string $key, string $value, int $expiration): array
    {
        $bytes = \strlen($value);
        $cmd = \sprintf("cas %s 0 %d %d %d\r\n%s\r\n", $key, $expiration, $bytes, $casToken, $value);
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
        if ('EXISTS' === $line) {
            return ['ok' => false, 'code' => MemcachedConstants::RES_DATA_EXISTS];
        }
        if ('NOT_FOUND' === $line) {
            return ['ok' => false, 'code' => MemcachedConstants::RES_NOTFOUND];
        }

        return ['ok' => false, 'code' => MemcachedConstants::RES_FAILURE];
    }

    /**
     * gets — get with CAS unique (ASCII gets).
     *
     * @param resource $socket
     *
     * @return array{value: string|false, cas: int, code: int}
     */
    public static function gets($socket, string $key): array
    {
        $cmd = \sprintf("gets %s\r\n", $key);
        if (!self::writeAll($socket, $cmd)) {
            return ['value' => false, 'cas' => 0, 'code' => MemcachedConstants::RES_WRITE_FAILURE];
        }
        $line = self::readLine($socket);
        if (null === $line) {
            return ['value' => false, 'cas' => 0, 'code' => MemcachedConstants::RES_READ_FAILURE];
        }
        if ('END' === $line) {
            return ['value' => false, 'cas' => 0, 'code' => MemcachedConstants::RES_NOTFOUND];
        }
        if (!\str_starts_with($line, 'VALUE ')) {
            return ['value' => false, 'cas' => 0, 'code' => MemcachedConstants::RES_FAILURE];
        }
        $parts = \explode(' ', $line);
        $bytes = isset($parts[3]) ? (int) $parts[3] : 0;
        $cas = isset($parts[4]) ? (int) $parts[4] : 0;
        $payload = self::readExact($socket, $bytes);
        if (null === $payload) {
            return ['value' => false, 'cas' => 0, 'code' => MemcachedConstants::RES_READ_FAILURE];
        }
        self::readExact($socket, 2);
        $end = self::readLine($socket);
        while (null !== $end && 'END' !== $end) {
            $end = self::readLine($socket);
        }

        return ['value' => $payload, 'cas' => $cas, 'code' => MemcachedConstants::RES_SUCCESS];
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
