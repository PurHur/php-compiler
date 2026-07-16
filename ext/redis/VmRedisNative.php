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
    private static function command($socket, array $args)
    {
        $payload = self::encode($args);
        $written = @\fwrite($socket, $payload);
        if (false === $written || $written < \strlen($payload)) {
            throw new \RedisException('Failed writing to Redis connection');
        }

        return self::readReply($socket);
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
