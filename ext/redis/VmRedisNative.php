<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

/**
 * Pure-PHP Redis RESP client over host TCP streams (PECL phpredis subset; #6098).
 *
 * No hiredis / runtime/*.c — connect/set/get via stream_socket_client + RESP framing.
 */
final class VmRedisNative
{
    /**
     * @return resource
     *
     * @throws \RedisException
     */
    public static function connect(string $host, int $port, float $timeout)
    {
        $remote = \sprintf('tcp://%s:%d', $host, $port);
        $errno = 0;
        $errstr = '';
        $socket = @\stream_socket_client(
            $remote,
            $errno,
            $errstr,
            $timeout > 0.0 ? $timeout : 60.0,
            \STREAM_CLIENT_CONNECT
        );
        if (false === $socket) {
            $message = '' !== $errstr ? $errstr : 'Connection failed';
            throw new \RedisException($message, (int) $errno);
        }
        \stream_set_timeout($socket, (int) \max(1, (int) \ceil($timeout > 0.0 ? $timeout : 60.0)));

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
     * @throws \RedisException
     */
    public static function set($socket, string $key, string $value): bool
    {
        $reply = self::command($socket, ['SET', $key, $value]);
        if (\is_string($reply) && 'OK' === $reply) {
            return true;
        }
        if (true === $reply) {
            return true;
        }

        throw new \RedisException(self::errorMessage($reply, 'SET failed'));
    }

    /**
     * @param resource $socket
     *
     * @return string|false
     *
     * @throws \RedisException
     */
    public static function get($socket, string $key)
    {
        $reply = self::command($socket, ['GET', $key]);
        if (null === $reply) {
            return false;
        }
        if (\is_string($reply)) {
            return $reply;
        }

        throw new \RedisException(self::errorMessage($reply, 'GET failed'));
    }

    /**
     * @param resource     $socket
     * @param list<string> $args
     *
     * @return mixed
     *
     * @throws \RedisException
     */
    public static function command($socket, array $args)
    {
        $payload = self::encode($args);
        $written = @\fwrite($socket, $payload);
        if (false === $written || $written < \strlen($payload)) {
            throw new \RedisException('Failed writing to Redis connection');
        }

        return self::readReply($socket);
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function del($socket, string ...$keys): int
    {
        if ([] === $keys) {
            return 0;
        }
        $reply = self::command($socket, \array_merge(['DEL'], $keys));
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'DEL failed'));
        }

        return $reply;
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function exists($socket, string ...$keys): int
    {
        if ([] === $keys) {
            return 0;
        }
        $reply = self::command($socket, \array_merge(['EXISTS'], $keys));
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'EXISTS failed'));
        }

        return $reply;
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function ping($socket, ?string $message = null): string|true
    {
        $args = null === $message ? ['PING'] : ['PING', $message];
        $reply = self::command($socket, $args);
        if (null === $message) {
            if (\is_string($reply) && 'PONG' === $reply) {
                return true;
            }
            throw new \RedisException(self::errorMessage($reply, 'PING failed'));
        }
        if (\is_string($reply)) {
            return $reply;
        }

        throw new \RedisException(self::errorMessage($reply, 'PING failed'));
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function auth($socket, string $password, ?string $username = null): bool
    {
        $args = null === $username ? ['AUTH', $password] : ['AUTH', $username, $password];
        $reply = self::command($socket, $args);
        if (\is_string($reply) && 'OK' === $reply) {
            return true;
        }
        if (true === $reply) {
            return true;
        }

        throw new \RedisException(self::errorMessage($reply, 'AUTH failed'));
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function select($socket, int $db): bool
    {
        $reply = self::command($socket, ['SELECT', (string) $db]);
        if (\is_string($reply) && 'OK' === $reply) {
            return true;
        }
        if (true === $reply) {
            return true;
        }

        throw new \RedisException(self::errorMessage($reply, 'SELECT failed'));
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function hSet($socket, string $key, string $field, string $value): int
    {
        $reply = self::command($socket, ['HSET', $key, $field, $value]);
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'HSET failed'));
        }

        return $reply;
    }

    /**
     * @param resource $socket
     *
     * @return string|false
     *
     * @throws \RedisException
     */
    public static function hGet($socket, string $key, string $field)
    {
        $reply = self::command($socket, ['HGET', $key, $field]);
        if (null === $reply) {
            return false;
        }
        if (\is_string($reply)) {
            return $reply;
        }

        throw new \RedisException(self::errorMessage($reply, 'HGET failed'));
    }

    /**
     * @param resource $socket
     *
     * @return array<string, string>
     *
     * @throws \RedisException
     */
    public static function hGetAll($socket, string $key): array
    {
        $reply = self::command($socket, ['HGETALL', $key]);
        if (null === $reply) {
            return [];
        }
        if (!\is_array($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'HGETALL failed'));
        }
        $out = [];
        $n = \count($reply);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $out[(string) $reply[$i]] = (string) $reply[$i + 1];
        }

        return $out;
    }

    /** @param list<string> $args */
    private static function encode(array $args): string
    {
        $out = '*'.\count($args)."\r\n";
        foreach ($args as $arg) {
            $out .= '$'.\strlen($arg)."\r\n".$arg."\r\n";
        }

        return $out;
    }

    /**
     * @param resource $socket
     *
     * @return mixed
     *
     * @throws \RedisException
     */
    private static function readReply($socket)
    {
        $line = self::readLine($socket);
        if ('' === $line) {
            throw new \RedisException('read error on connection');
        }
        $type = $line[0];
        $payload = \substr($line, 1);
        switch ($type) {
            case '+':
                return $payload;
            case '-':
                throw new \RedisException($payload);
            case ':':
                return (int) $payload;
            case '$':
                $len = (int) $payload;
                if (-1 === $len) {
                    return null;
                }
                $data = self::readExact($socket, $len + 2);
                if (\strlen($data) < $len + 2) {
                    throw new \RedisException('protocol error, got bad bulk length');
                }

                return \substr($data, 0, $len);
            case '*':
                $count = (int) $payload;
                if (-1 === $count) {
                    return null;
                }
                $items = [];
                for ($i = 0; $i < $count; ++$i) {
                    $items[] = self::readReply($socket);
                }

                return $items;
            default:
                throw new \RedisException('protocol error, got unexpected reply type');
        }
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    private static function readLine($socket): string
    {
        $line = @\fgets($socket);
        if (false === $line) {
            throw new \RedisException('read error on connection');
        }

        return \rtrim($line, "\r\n");
    }

    /** @param resource $socket */
    private static function readExact($socket, int $length): string
    {
        $buf = '';
        while (\strlen($buf) < $length) {
            $chunk = @\fread($socket, $length - \strlen($buf));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $buf .= $chunk;
        }

        return $buf;
    }

    private static function errorMessage(mixed $reply, string $fallback): string
    {
        if (\is_string($reply) && '' !== $reply) {
            return $reply;
        }

        return $fallback;
    }
}
