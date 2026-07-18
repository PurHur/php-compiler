<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Redis depth methods after #6098 — del/exists/ping/auth/select/isConnected + hash family (#20564).
 */
final class RedisDel extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('del');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::del()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError('Redis::del() expects at least 1 argument, 0 given');
        }
        $keys = [];
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            $keys[] = $this->stringArg($frame->calledArgs[$i], 'Redis::del', $i - 1, 'key');
        }
        $socket = VmRedis::requireSocket($receiver, 'Redis::del');
        $n = VmRedisNative::del($socket, ...$keys);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($n);
        }
    }
}

final class RedisExists extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('exists');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::exists()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError('Redis::exists() expects at least 1 argument, 0 given');
        }
        $keys = [];
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            $keys[] = $this->stringArg($frame->calledArgs[$i], 'Redis::exists', $i - 1, 'key');
        }
        $socket = VmRedis::requireSocket($receiver, 'Redis::exists');
        $n = VmRedisNative::exists($socket, ...$keys);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($n);
        }
    }
}

final class RedisPing extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('ping');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::ping()');
        $message = null;
        if (\count($frame->calledArgs) >= 2) {
            $message = $this->stringArg($frame->calledArgs[1], 'Redis::ping', 0, 'message');
        }
        $socket = VmRedis::requireSocket($receiver, 'Redis::ping');
        $reply = VmRedisNative::ping($socket, $message);
        if (null === $frame->returnVar) {
            return;
        }
        if (true === $reply) {
            $frame->returnVar->bool(true);

            return;
        }
        $frame->returnVar->string($reply);
    }
}

final class RedisAuth extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('auth');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::auth()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 1) {
            throw new \ArgumentCountError('Redis::auth() expects at least 1 argument, 0 given');
        }
        $socket = VmRedis::requireSocket($receiver, 'Redis::auth');
        if ($argc >= 2) {
            $user = $this->stringArg($frame->calledArgs[1], 'Redis::auth', 0, 'user');
            $pass = $this->stringArg($frame->calledArgs[2], 'Redis::auth', 1, 'password');
            $ok = VmRedisNative::auth($socket, $pass, $user);
        } else {
            $pass = $this->stringArg($frame->calledArgs[1], 'Redis::auth', 0, 'password');
            $ok = VmRedisNative::auth($socket, $pass);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class RedisSelect extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('select');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::select()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::select() expects exactly 1 argument, 0 given');
        }
        $db = $this->intArg($frame->calledArgs[1], 'Redis::select', 0, 'db');
        $socket = VmRedis::requireSocket($receiver, 'Redis::select');
        $ok = VmRedisNative::select($socket, $db);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}

final class RedisIsConnected extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('isConnected');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::isConnected()');
        $state = VmRedis::state($receiver);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($state->connected && null !== $state->socket);
        }
    }
}

final class RedisHSet extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('hSet');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::hSet()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'Redis::hSet() expects at least 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::hSet', 0, 'key');
        $field = $this->stringArg($frame->calledArgs[2], 'Redis::hSet', 1, 'member');
        $value = VmRedis::coerceValueToString($frame->calledArgs[3], 'Redis::hSet', 2, 'value');
        $socket = VmRedis::requireSocket($receiver, 'Redis::hSet');
        $n = VmRedisNative::hSet($socket, $key, $field, $value);
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($n);
        }
    }
}

final class RedisHGet extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('hGet');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::hGet()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::hGet() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::hGet', 0, 'key');
        $field = $this->stringArg($frame->calledArgs[2], 'Redis::hGet', 1, 'member');
        $socket = VmRedis::requireSocket($receiver, 'Redis::hGet');
        $value = VmRedisNative::hGet($socket, $key, $field);
        if (null === $frame->returnVar) {
            return;
        }
        if (false === $value) {
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->string($value);
    }
}

final class RedisHGetAll extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('hGetAll');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::hGetAll()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::hGetAll() expects exactly 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::hGetAll', 0, 'key');
        $socket = VmRedis::requireSocket($receiver, 'Redis::hGetAll');
        $map = VmRedisNative::hGetAll($socket, $key);
        if (null === $frame->returnVar) {
            return;
        }
        $ht = new HashTable();
        foreach ($map as $k => $v) {
            $slot = new Variable();
            $slot->string($v);
            $ht->add((string) $k, $slot);
        }
        $frame->returnVar->array($ht);
    }
}
