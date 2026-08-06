<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Redis depth methods after #6098 — del/exists/ping/auth/select/isConnected + hash family (#20564)
 * + list/set/zset/multi/pipeline/eval/expire/ttl/incr family (#20612).
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
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::del', \array_merge(['DEL'], $keys));
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('DEL failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
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
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::exists', \array_merge(['EXISTS'], $keys));
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('EXISTS failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
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
        $args = null === $message ? ['PING'] : ['PING', $message];
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::ping', $args);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $message) {
            if (\is_string($reply) && 'PONG' === $reply) {
                $frame->returnVar->bool(true);

                return;
            }
            throw new \RedisException('PING failed');
        }
        if (\is_string($reply)) {
            $frame->returnVar->string($reply);

            return;
        }
        throw new \RedisException('PING failed');
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
        if ($argc >= 2) {
            $user = $this->stringArg($frame->calledArgs[1], 'Redis::auth', 0, 'user');
            $pass = $this->stringArg($frame->calledArgs[2], 'Redis::auth', 1, 'password');
            $args = ['AUTH', $user, $pass];
        } else {
            $pass = $this->stringArg($frame->calledArgs[1], 'Redis::auth', 0, 'password');
            $args = ['AUTH', $pass];
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::auth', $args);
        if ($handled) {
            return;
        }
        $ok = (\is_string($reply) && 'OK' === $reply) || true === $reply;
        if (!$ok) {
            VmRedis::noteError($receiver, 'AUTH failed');
            throw new \RedisException('AUTH failed');
        }
        if ($argc >= 2) {
            $state = VmRedis::state($receiver);
            $state->auth = [$user, $pass];
        } else {
            $state = VmRedis::state($receiver);
            $state->auth = $pass;
        }
        $state->lastError = null;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
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
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::select', ['SELECT', (string) $db]);
        if ($handled) {
            return;
        }
        $ok = (\is_string($reply) && 'OK' === $reply) || true === $reply;
        if (!$ok) {
            VmRedis::noteError($receiver, 'SELECT failed');
            throw new \RedisException('SELECT failed');
        }
        VmRedis::state($receiver)->dbNum = $db;
        VmRedis::state($receiver)->lastError = null;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
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
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::hSet', ['HSET', $key, $field, $value]);
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('HSET failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
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
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::hGet', ['HGET', $key, $field]);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $reply) {
            $frame->returnVar->bool(false);

            return;
        }
        if (!\is_string($reply)) {
            throw new \RedisException('HGET failed');
        }
        $frame->returnVar->string($reply);
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
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::hGetAll', ['HGETALL', $key]);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $reply) {
            $frame->returnVar->array(new HashTable());

            return;
        }
        if (!\is_array($reply)) {
            throw new \RedisException('HGETALL failed');
        }
        $map = [];
        $n = \count($reply);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $map[(string) $reply[$i]] = (string) $reply[$i + 1];
        }
        $frame->returnVar->array(VmRedis::stringMapToHashTable($map));
    }
}

final class RedisLPush extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('lPush');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::lPush()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::lPush() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::lPush', 0, 'key');
        $values = [];
        for ($i = 2; $i < \count($frame->calledArgs); ++$i) {
            $values[] = VmRedis::coerceValueToString($frame->calledArgs[$i], 'Redis::lPush', $i - 1, 'value');
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::lPush', \array_merge(['LPUSH', $key], $values));
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('LPUSH failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisRPush extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('rPush');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::rPush()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::rPush() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::rPush', 0, 'key');
        $values = [];
        for ($i = 2; $i < \count($frame->calledArgs); ++$i) {
            $values[] = VmRedis::coerceValueToString($frame->calledArgs[$i], 'Redis::rPush', $i - 1, 'value');
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::rPush', \array_merge(['RPUSH', $key], $values));
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('RPUSH failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisLPop extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('lPop');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::lPop()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::lPop() expects exactly 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::lPop', 0, 'key');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::lPop', ['LPOP', $key]);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $reply) {
            $frame->returnVar->bool(false);

            return;
        }
        if (!\is_string($reply)) {
            throw new \RedisException('LPOP failed');
        }
        $frame->returnVar->string($reply);
    }
}

final class RedisRPop extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('rPop');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::rPop()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::rPop() expects exactly 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::rPop', 0, 'key');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::rPop', ['RPOP', $key]);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $reply) {
            $frame->returnVar->bool(false);

            return;
        }
        if (!\is_string($reply)) {
            throw new \RedisException('RPOP failed');
        }
        $frame->returnVar->string($reply);
    }
}

final class RedisLRange extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('lRange');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::lRange()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'Redis::lRange() expects exactly 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::lRange', 0, 'key');
        $start = $this->intArg($frame->calledArgs[2], 'Redis::lRange', 1, 'start');
        $end = $this->intArg($frame->calledArgs[3], 'Redis::lRange', 2, 'end');
        [$handled, $reply] = $this->commandOrQueue(
            $frame,
            $receiver,
            'Redis::lRange',
            ['LRANGE', $key, (string) $start, (string) $end]
        );
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $reply) {
            $frame->returnVar->array(new HashTable());

            return;
        }
        if (!\is_array($reply)) {
            throw new \RedisException('LRANGE failed');
        }
        $list = [];
        foreach ($reply as $item) {
            $list[] = null === $item ? '' : (string) $item;
        }
        $frame->returnVar->array(VmRedis::stringListToHashTable($list));
    }
}

final class RedisSAdd extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('sAdd');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::sAdd()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::sAdd() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::sAdd', 0, 'key');
        $members = [];
        for ($i = 2; $i < \count($frame->calledArgs); ++$i) {
            $members[] = VmRedis::coerceValueToString($frame->calledArgs[$i], 'Redis::sAdd', $i - 1, 'value');
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::sAdd', \array_merge(['SADD', $key], $members));
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('SADD failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisSRem extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('sRem');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::sRem()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::sRem() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::sRem', 0, 'key');
        $members = [];
        for ($i = 2; $i < \count($frame->calledArgs); ++$i) {
            $members[] = VmRedis::coerceValueToString($frame->calledArgs[$i], 'Redis::sRem', $i - 1, 'value');
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::sRem', \array_merge(['SREM', $key], $members));
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('SREM failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisSMembers extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('sMembers');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::sMembers()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::sMembers() expects exactly 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::sMembers', 0, 'key');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::sMembers', ['SMEMBERS', $key]);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $reply) {
            $frame->returnVar->array(new HashTable());

            return;
        }
        if (!\is_array($reply)) {
            throw new \RedisException('SMEMBERS failed');
        }
        $list = [];
        foreach ($reply as $item) {
            $list[] = null === $item ? '' : (string) $item;
        }
        $frame->returnVar->array(VmRedis::stringListToHashTable($list));
    }
}

final class RedisSIsMember extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('sIsMember');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::sIsMember()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::sIsMember() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::sIsMember', 0, 'key');
        $member = VmRedis::coerceValueToString($frame->calledArgs[2], 'Redis::sIsMember', 1, 'value');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::sIsMember', ['SISMEMBER', $key, $member]);
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('SISMEMBER failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($reply > 0);
        }
    }
}

final class RedisZAdd extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('zAdd');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::zAdd()');
        $argc = \count($frame->calledArgs) - 1;
        if ($argc < 3 || 0 !== ($argc - 1) % 2) {
            throw new \ArgumentCountError(
                'Redis::zAdd() expects at least 3 arguments with score/member pairs, '.$argc.' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::zAdd', 0, 'key');
        $args = ['ZADD', $key];
        for ($i = 2; $i < \count($frame->calledArgs); $i += 2) {
            $score = $this->floatArg($frame->calledArgs[$i], 'Redis::zAdd', $i - 1, 'score');
            $member = VmRedis::coerceValueToString($frame->calledArgs[$i + 1], 'Redis::zAdd', $i, 'member');
            $args[] = (string) $score;
            $args[] = $member;
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::zAdd', $args);
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('ZADD failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisZRange extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('zRange');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::zRange()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'Redis::zRange() expects at least 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::zRange', 0, 'key');
        $start = $this->intArg($frame->calledArgs[2], 'Redis::zRange', 1, 'start');
        $end = $this->intArg($frame->calledArgs[3], 'Redis::zRange', 2, 'end');
        $withScores = false;
        if (\count($frame->calledArgs) >= 5) {
            $opt = $frame->calledArgs[4]->resolveIndirect();
            if (Variable::TYPE_BOOLEAN === $opt->type) {
                $withScores = $opt->toBool();
            } elseif (Variable::TYPE_INTEGER === $opt->type) {
                $withScores = $opt->toInt() !== 0;
            }
        }
        $args = ['ZRANGE', $key, (string) $start, (string) $end];
        if ($withScores) {
            $args[] = 'WITHSCORES';
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::zRange', $args);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $reply) {
            $frame->returnVar->array(new HashTable());

            return;
        }
        if (!\is_array($reply)) {
            throw new \RedisException('ZRANGE failed');
        }
        if (!$withScores) {
            $list = [];
            foreach ($reply as $item) {
                $list[] = null === $item ? '' : (string) $item;
            }
            $frame->returnVar->array(VmRedis::stringListToHashTable($list));

            return;
        }
        $map = [];
        $n = \count($reply);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $map[(string) $reply[$i]] = (string) $reply[$i + 1];
        }
        $frame->returnVar->array(VmRedis::stringMapToHashTable($map));
    }
}

final class RedisZRem extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('zRem');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::zRem()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::zRem() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::zRem', 0, 'key');
        $members = [];
        for ($i = 2; $i < \count($frame->calledArgs); ++$i) {
            $members[] = VmRedis::coerceValueToString($frame->calledArgs[$i], 'Redis::zRem', $i - 1, 'member');
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::zRem', \array_merge(['ZREM', $key], $members));
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('ZREM failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisMulti extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('multi');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::multi()');
        $mode = RedisConstants::MULTI;
        if (\count($frame->calledArgs) >= 2) {
            $mode = $this->intArg($frame->calledArgs[1], 'Redis::multi', 0, 'value');
        }
        $state = VmRedis::state($receiver);
        if (0 !== $state->mode) {
            throw new \RedisException('Redis::multi() cannot start a transaction while already in multi/pipeline mode');
        }
        $socket = VmRedis::requireSocket($receiver, 'Redis::multi');
        if (RedisConstants::PIPELINE === $mode) {
            $state->mode = RedisConstants::PIPELINE;
            $state->pipelinePending = 0;
        } else {
            $reply = VmRedisNative::command($socket, ['MULTI']);
            if (!(\is_string($reply) && 'OK' === $reply) && true !== $reply) {
                throw new \RedisException('MULTI failed');
            }
            $state->mode = RedisConstants::MULTI;
            $state->pipelinePending = 0;
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($receiver);
        }
    }
}

final class RedisPipeline extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('pipeline');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::pipeline()');
        $state = VmRedis::state($receiver);
        if (0 !== $state->mode) {
            throw new \RedisException('Redis::pipeline() cannot start while already in multi/pipeline mode');
        }
        VmRedis::requireSocket($receiver, 'Redis::pipeline');
        $state->mode = RedisConstants::PIPELINE;
        $state->pipelinePending = 0;
        if (null !== $frame->returnVar) {
            $frame->returnVar->object($receiver);
        }
    }
}

final class RedisExec extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('exec');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::exec()');
        $state = VmRedis::state($receiver);
        $socket = VmRedis::requireSocket($receiver, 'Redis::exec');
        if (RedisConstants::MULTI === $state->mode) {
            $reply = VmRedisNative::command($socket, ['EXEC']);
            $state->mode = 0;
            $state->pipelinePending = 0;
            if (null === $frame->returnVar) {
                return;
            }
            if (null === $reply) {
                $frame->returnVar->bool(false);

                return;
            }
            if (!\is_array($reply)) {
                throw new \RedisException('EXEC failed');
            }
            $ht = new HashTable();
            foreach ($reply as $item) {
                $ht->append(VmRedis::replyToVariable($item));
            }
            $frame->returnVar->array($ht);

            return;
        }
        if (RedisConstants::PIPELINE === $state->mode) {
            $pending = $state->pipelinePending;
            $state->mode = 0;
            $state->pipelinePending = 0;
            $replies = VmRedisNative::readReplies($socket, $pending);
            if (null === $frame->returnVar) {
                return;
            }
            $ht = new HashTable();
            foreach ($replies as $item) {
                $ht->append(VmRedis::replyToVariable($item));
            }
            $frame->returnVar->array($ht);

            return;
        }
        throw new \RedisException('Redis::exec() called without an active multi/pipeline');
    }
}

final class RedisEval extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('eval');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::eval()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::eval() expects at least 1 argument, 0 given');
        }
        $script = $this->stringArg($frame->calledArgs[1], 'Redis::eval', 0, 'script');
        $keysAndArgs = [];
        $numKeys = 0;
        if (\count($frame->calledArgs) >= 3) {
            $keysAndArgs = VmRedis::coerceStringListArg($frame->calledArgs[2], 'Redis::eval', 1, 'args');
        }
        if (\count($frame->calledArgs) >= 4) {
            $numKeys = $this->intArg($frame->calledArgs[3], 'Redis::eval', 2, 'num_keys');
        }
        [$handled, $reply] = $this->commandOrQueue(
            $frame,
            $receiver,
            'Redis::eval',
            \array_merge(['EVAL', $script, (string) $numKeys], $keysAndArgs)
        );
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $converted = VmRedis::replyToVariable($reply);
        $frame->returnVar->copyFrom($converted);
    }
}

final class RedisExpire extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('expire');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::expire()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::expire() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::expire', 0, 'key');
        $ttl = $this->intArg($frame->calledArgs[2], 'Redis::expire', 1, 'ttl');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::expire', ['EXPIRE', $key, (string) $ttl]);
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('EXPIRE failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($reply > 0);
        }
    }
}

final class RedisTtl extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('ttl');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::ttl()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::ttl() expects exactly 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::ttl', 0, 'key');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::ttl', ['TTL', $key]);
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('TTL failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisIncr extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('incr');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::incr()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::incr() expects at least 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::incr', 0, 'key');
        $by = 1;
        if (\count($frame->calledArgs) >= 3) {
            $by = $this->intArg($frame->calledArgs[2], 'Redis::incr', 1, 'value');
        }
        $args = 1 === $by ? ['INCR', $key] : ['INCRBY', $key, (string) $by];
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::incr', $args);
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('INCR failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisDecr extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('decr');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::decr()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::decr() expects at least 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::decr', 0, 'key');
        $by = 1;
        if (\count($frame->calledArgs) >= 3) {
            $by = $this->intArg($frame->calledArgs[2], 'Redis::decr', 1, 'value');
        }
        $args = 1 === $by ? ['DECR', $key] : ['DECRBY', $key, (string) $by];
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::decr', $args);
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('DECR failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisKeys extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('keys');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::keys()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::keys() expects exactly 1 argument, 0 given');
        }
        $pattern = $this->stringArg($frame->calledArgs[1], 'Redis::keys', 0, 'pattern');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::keys', ['KEYS', $pattern]);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $reply) {
            $frame->returnVar->array(new HashTable());

            return;
        }
        if (!\is_array($reply)) {
            throw new \RedisException('KEYS failed');
        }
        $list = [];
        foreach ($reply as $item) {
            $list[] = null === $item ? '' : (string) $item;
        }
        $frame->returnVar->array(VmRedis::stringListToHashTable($list));
    }
}

final class RedisMGet extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('mget');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::mget()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::mget() expects at least 1 argument, 0 given');
        }
        $first = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY === $first->type) {
            $keys = VmRedis::coerceStringListArg($frame->calledArgs[1], 'Redis::mget', 0, 'keys');
        } else {
            $keys = [];
            for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
                $keys[] = $this->stringArg($frame->calledArgs[$i], 'Redis::mget', $i - 1, 'key');
            }
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::mget', \array_merge(['MGET'], $keys));
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (null === $reply) {
            $frame->returnVar->array(new HashTable());

            return;
        }
        if (!\is_array($reply)) {
            throw new \RedisException('MGET failed');
        }
        $ht = new HashTable();
        foreach ($reply as $item) {
            $ht->append(VmRedis::replyToVariable($item));
        }
        $frame->returnVar->array($ht);
    }
}

final class RedisMSet extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('mset');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::mset()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::mset() expects exactly 1 argument, 0 given');
        }
        $pairs = VmRedis::coerceStringMapArg($frame->calledArgs[1], 'Redis::mset', 0, 'array');
        $args = ['MSET'];
        foreach ($pairs as $k => $v) {
            $args[] = $k;
            $args[] = $v;
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::mset', $args);
        if ($handled) {
            return;
        }
        $ok = (\is_string($reply) && 'OK' === $reply) || true === $reply;
        if (!$ok) {
            throw new \RedisException('MSET failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
