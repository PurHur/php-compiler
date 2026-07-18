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
     * @throws \RedisException
     */
    public static function writeCommand($socket, array $args): void
    {
        $payload = self::encode($args);
        $written = @\fwrite($socket, $payload);
        if (false === $written || $written < \strlen($payload)) {
            throw new \RedisException('Failed writing to Redis connection');
        }
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
        self::writeCommand($socket, $args);

        return self::readReply($socket);
    }

    /**
     * @param resource $socket
     *
     * @return list<mixed>
     *
     * @throws \RedisException
     */
    public static function readReplies($socket, int $count): array
    {
        $out = [];
        for ($i = 0; $i < $count; ++$i) {
            $out[] = self::readReply($socket);
        }

        return $out;
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

    /**
     * @param resource     $socket
     * @param list<string> $values
     *
     * @throws \RedisException
     */
    public static function listPush($socket, string $op, string $key, array $values): int
    {
        if ([] === $values) {
            return 0;
        }
        $reply = self::command($socket, \array_merge([$op, $key], $values));
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, $op.' failed'));
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
    public static function listPop($socket, string $op, string $key)
    {
        $reply = self::command($socket, [$op, $key]);
        if (null === $reply) {
            return false;
        }
        if (\is_string($reply)) {
            return $reply;
        }

        throw new \RedisException(self::errorMessage($reply, $op.' failed'));
    }

    /**
     * @param resource $socket
     *
     * @return list<string>
     *
     * @throws \RedisException
     */
    public static function lRange($socket, string $key, int $start, int $end): array
    {
        $reply = self::command($socket, ['LRANGE', $key, (string) $start, (string) $end]);
        if (null === $reply) {
            return [];
        }
        if (!\is_array($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'LRANGE failed'));
        }
        $out = [];
        foreach ($reply as $item) {
            $out[] = null === $item ? '' : (string) $item;
        }

        return $out;
    }

    /**
     * @param resource     $socket
     * @param list<string> $members
     *
     * @throws \RedisException
     */
    public static function sAdd($socket, string $key, array $members): int
    {
        if ([] === $members) {
            return 0;
        }
        $reply = self::command($socket, \array_merge(['SADD', $key], $members));
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'SADD failed'));
        }

        return $reply;
    }

    /**
     * @param resource     $socket
     * @param list<string> $members
     *
     * @throws \RedisException
     */
    public static function sRem($socket, string $key, array $members): int
    {
        if ([] === $members) {
            return 0;
        }
        $reply = self::command($socket, \array_merge(['SREM', $key], $members));
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'SREM failed'));
        }

        return $reply;
    }

    /**
     * @param resource $socket
     *
     * @return list<string>
     *
     * @throws \RedisException
     */
    public static function sMembers($socket, string $key): array
    {
        $reply = self::command($socket, ['SMEMBERS', $key]);
        if (null === $reply) {
            return [];
        }
        if (!\is_array($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'SMEMBERS failed'));
        }
        $out = [];
        foreach ($reply as $item) {
            $out[] = null === $item ? '' : (string) $item;
        }

        return $out;
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function sIsMember($socket, string $key, string $member): bool
    {
        $reply = self::command($socket, ['SISMEMBER', $key, $member]);
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'SISMEMBER failed'));
        }

        return $reply > 0;
    }

    /**
     * @param resource          $socket
     * @param list<float|string> $scoreMembers alternating score, member
     *
     * @throws \RedisException
     */
    public static function zAdd($socket, string $key, array $scoreMembers): int
    {
        if ([] === $scoreMembers) {
            return 0;
        }
        $args = ['ZADD', $key];
        foreach ($scoreMembers as $part) {
            $args[] = (string) $part;
        }
        $reply = self::command($socket, $args);
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'ZADD failed'));
        }

        return $reply;
    }

    /**
     * @param resource $socket
     *
     * @return list<string>|array<string, string>
     *
     * @throws \RedisException
     */
    public static function zRange($socket, string $key, int $start, int $end, bool $withScores = false): array
    {
        $args = ['ZRANGE', $key, (string) $start, (string) $end];
        if ($withScores) {
            $args[] = 'WITHSCORES';
        }
        $reply = self::command($socket, $args);
        if (null === $reply) {
            return [];
        }
        if (!\is_array($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'ZRANGE failed'));
        }
        if (!$withScores) {
            $out = [];
            foreach ($reply as $item) {
                $out[] = null === $item ? '' : (string) $item;
            }

            return $out;
        }
        $map = [];
        $n = \count($reply);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $map[(string) $reply[$i]] = (string) $reply[$i + 1];
        }

        return $map;
    }

    /**
     * @param resource     $socket
     * @param list<string> $members
     *
     * @throws \RedisException
     */
    public static function zRem($socket, string $key, array $members): int
    {
        if ([] === $members) {
            return 0;
        }
        $reply = self::command($socket, \array_merge(['ZREM', $key], $members));
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'ZREM failed'));
        }

        return $reply;
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function expire($socket, string $key, int $ttl): bool
    {
        $reply = self::command($socket, ['EXPIRE', $key, (string) $ttl]);
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'EXPIRE failed'));
        }

        return $reply > 0;
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function ttl($socket, string $key): int
    {
        $reply = self::command($socket, ['TTL', $key]);
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'TTL failed'));
        }

        return $reply;
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function incrBy($socket, string $key, int $by): int
    {
        $args = 1 === $by ? ['INCR', $key] : ['INCRBY', $key, (string) $by];
        $reply = self::command($socket, $args);
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'INCR failed'));
        }

        return $reply;
    }

    /**
     * @param resource $socket
     *
     * @throws \RedisException
     */
    public static function decrBy($socket, string $key, int $by): int
    {
        $args = 1 === $by ? ['DECR', $key] : ['DECRBY', $key, (string) $by];
        $reply = self::command($socket, $args);
        if (!\is_int($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'DECR failed'));
        }

        return $reply;
    }

    /**
     * @param resource $socket
     *
     * @return list<string>
     *
     * @throws \RedisException
     */
    public static function keys($socket, string $pattern): array
    {
        $reply = self::command($socket, ['KEYS', $pattern]);
        if (null === $reply) {
            return [];
        }
        if (!\is_array($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'KEYS failed'));
        }
        $out = [];
        foreach ($reply as $item) {
            $out[] = null === $item ? '' : (string) $item;
        }

        return $out;
    }

    /**
     * @param resource     $socket
     * @param list<string> $keys
     *
     * @return list<string|null>
     *
     * @throws \RedisException
     */
    public static function mget($socket, array $keys): array
    {
        if ([] === $keys) {
            return [];
        }
        $reply = self::command($socket, \array_merge(['MGET'], $keys));
        if (null === $reply) {
            return \array_fill(0, \count($keys), null);
        }
        if (!\is_array($reply)) {
            throw new \RedisException(self::errorMessage($reply, 'MGET failed'));
        }
        $out = [];
        foreach ($reply as $item) {
            $out[] = null === $item ? null : (string) $item;
        }

        return $out;
    }

    /**
     * @param resource            $socket
     * @param array<string,string> $pairs
     *
     * @throws \RedisException
     */
    public static function mset($socket, array $pairs): bool
    {
        if ([] === $pairs) {
            return true;
        }
        $args = ['MSET'];
        foreach ($pairs as $k => $v) {
            $args[] = (string) $k;
            $args[] = (string) $v;
        }
        $reply = self::command($socket, $args);
        if (\is_string($reply) && 'OK' === $reply) {
            return true;
        }
        if (true === $reply) {
            return true;
        }

        throw new \RedisException(self::errorMessage($reply, 'MSET failed'));
    }

    /**
     * @param resource     $socket
     * @param list<string> $keysAndArgs
     *
     * @return mixed
     *
     * @throws \RedisException
     */
    public static function eval($socket, string $script, int $numKeys, array $keysAndArgs)
    {
        return self::command($socket, \array_merge(['EVAL', $script, (string) $numKeys], $keysAndArgs));
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
