<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmCallable;

/**
 * Redis pub/sub + SCAN + streams + companions after #20612 (#20682, PECL phpredis).
 */
final class RedisPublish extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('publish');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::publish()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::publish() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $channel = $this->stringArg($frame->calledArgs[1], 'Redis::publish', 0, 'channel');
        $message = VmRedis::coerceValueToString($frame->calledArgs[2], 'Redis::publish', 1, 'message');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::publish', ['PUBLISH', $channel, $message]);
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('PUBLISH failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

class RedisSubscribe extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('subscribe');
    }

    public function execute(Frame $frame): void
    {
        $this->runSubscribeLoop($frame, 'Redis::subscribe', 'SUBSCRIBE', false);
    }

    protected function runSubscribeLoop(Frame $frame, string $label, string $op, bool $pattern): void
    {
        $receiver = $this->receiver($frame, $label.'()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                $label.'() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $channels = VmRedis::coerceStringListArg($frame->calledArgs[1], $label, 0, $pattern ? 'patterns' : 'channels');
        if ([] === $channels) {
            throw new \RedisException($label.'(): At least one channel/pattern is required');
        }
        $callback = $frame->calledArgs[2];
        $ctx = $frame->vmContext;
        if (null === $ctx) {
            throw new \LogicException($label.'() missing VM context');
        }
        $state = VmRedis::state($receiver);
        if (0 !== $state->mode) {
            throw new \RedisException($label.'() cannot run while in multi/pipeline mode');
        }
        $socket = VmRedis::requireSocket($receiver, $label);
        VmRedisNative::writeCommand($socket, \array_merge([$op], $channels));
        // One subscribe confirmation reply per channel/pattern.
        for ($i = 0; $i < \count($channels); ++$i) {
            VmRedisNative::readReply($socket);
        }
        $redisVar = new Variable();
        $redisVar->object($receiver);
        while (true) {
            $msg = VmRedisNative::readReply($socket);
            if (!\is_array($msg) || \count($msg) < 3) {
                continue;
            }
            $type = \strtolower((string) $msg[0]);
            if ('message' === $type || 'pmessage' === $type) {
                if ('pmessage' === $type && \count($msg) >= 4) {
                    $channel = (string) $msg[2];
                    $payload = (string) $msg[3];
                } else {
                    $channel = (string) $msg[1];
                    $payload = (string) $msg[2];
                }
                $chVar = new Variable();
                $chVar->string($channel);
                $payVar = new Variable();
                $payVar->string($payload);
                $ret = VmCallable::invokeAs($label, $ctx, $callback, $redisVar, $chVar, $payVar);
                $resolved = $ret->resolveIndirect();
                $keep = true;
                if (Variable::TYPE_BOOLEAN === $resolved->type) {
                    $keep = $resolved->toBool();
                } elseif (Variable::TYPE_INTEGER === $resolved->type) {
                    $keep = $resolved->toInt() !== 0;
                } elseif (Variable::TYPE_NULL === $resolved->type) {
                    $keep = true;
                }
                if (!$keep) {
                    $unsub = $pattern ? 'PUNSUBSCRIBE' : 'UNSUBSCRIBE';
                    try {
                        VmRedisNative::command($socket, [$unsub]);
                    } catch (\RedisException $e) {
                        // Connection may already be closing after callback-driven unsubscribe.
                    }
                    if (null !== $frame->returnVar) {
                        $frame->returnVar->bool(true);
                    }

                    return;
                }
            }
        }
    }
}

final class RedisPSubscribe extends RedisSubscribe
{
    public function __construct()
    {
        parent::__construct();
        // Re-bind handler name for Internal::getName() / errors.
        $this->name = 'psubscribe';
    }

    public function execute(Frame $frame): void
    {
        $this->runSubscribeLoop($frame, 'Redis::psubscribe', 'PSUBSCRIBE', true);
    }
}

class RedisScan extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('scan');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::scan()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::scan() expects at least 1 argument, 0 given');
        }
        $iteratorVar = $frame->calledArgs[1];
        $pattern = null;
        $count = 0;
        if (\count($frame->calledArgs) >= 3) {
            $pattern = $this->stringArg($frame->calledArgs[2], 'Redis::scan', 1, 'pattern');
        }
        if (\count($frame->calledArgs) >= 4) {
            $count = $this->intArg($frame->calledArgs[3], 'Redis::scan', 2, 'count');
        }
        $this->runScan($frame, $receiver, 'Redis::scan', 'SCAN', null, $iteratorVar, $pattern, $count, 'list');
    }

    /**
     * @param 'list'|'map'|'zmap' $shape
     */
    protected function runScan(
        Frame $frame,
        ObjectEntry $receiver,
        string $label,
        string $op,
        ?string $key,
        Variable $iteratorVar,
        ?string $pattern,
        int $count,
        string $shape
    ): void {
        $cursor = self::cursorFromIterator($iteratorVar);
        $args = null === $key ? [$op, $cursor] : [$op, $key, $cursor];
        if (null !== $pattern && '' !== $pattern) {
            $args[] = 'MATCH';
            $args[] = $pattern;
        }
        if ($count > 0) {
            $args[] = 'COUNT';
            $args[] = (string) $count;
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, $label, $args);
        if ($handled) {
            return;
        }
        if (!\is_array($reply) || \count($reply) < 2 || !\is_array($reply[1])) {
            throw new \RedisException($op.' failed');
        }
        $next = (int) $reply[0];
        $items = $reply[1];
        $iteratorVar->resolveIndirect()->int($next);
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $next && [] === $items) {
            $frame->returnVar->bool(false);

            return;
        }
        if ('list' === $shape) {
            $list = [];
            foreach ($items as $item) {
                $list[] = null === $item ? '' : (string) $item;
            }
            $frame->returnVar->array(VmRedis::stringListToHashTable($list));

            return;
        }
        $map = [];
        $n = \count($items);
        for ($i = 0; $i + 1 < $n; $i += 2) {
            $map[(string) $items[$i]] = (string) $items[$i + 1];
        }
        $frame->returnVar->array(VmRedis::stringMapToHashTable($map));
    }

    private static function cursorFromIterator(Variable $iteratorVar): string
    {
        $resolved = $iteratorVar->resolveIndirect();
        if (Variable::TYPE_NULL === $resolved->type) {
            return '0';
        }
        if (Variable::TYPE_BOOLEAN === $resolved->type) {
            return $resolved->toBool() ? '1' : '0';
        }
        if (Variable::TYPE_INTEGER === $resolved->type) {
            return (string) $resolved->toInt();
        }
        if (Variable::TYPE_STRING === $resolved->type) {
            $raw = $resolved->toString();

            return '' === $raw ? '0' : $raw;
        }
        if (Variable::TYPE_FLOAT === $resolved->type) {
            return (string) (int) $resolved->toFloat();
        }

        return '0';
    }
}

final class RedisHScan extends RedisScan
{
    public function __construct()
    {
        parent::__construct();
        $this->name = 'hscan';
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::hScan()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::hScan() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::hScan', 0, 'key');
        $iteratorVar = $frame->calledArgs[2];
        $pattern = null;
        $count = 0;
        if (\count($frame->calledArgs) >= 4) {
            $pattern = $this->stringArg($frame->calledArgs[3], 'Redis::hScan', 2, 'pattern');
        }
        if (\count($frame->calledArgs) >= 5) {
            $count = $this->intArg($frame->calledArgs[4], 'Redis::hScan', 3, 'count');
        }
        $this->runScan($frame, $receiver, 'Redis::hScan', 'HSCAN', $key, $iteratorVar, $pattern, $count, 'map');
    }
}

final class RedisSScan extends RedisScan
{
    public function __construct()
    {
        parent::__construct();
        $this->name = 'sscan';
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::sScan()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::sScan() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::sScan', 0, 'key');
        $iteratorVar = $frame->calledArgs[2];
        $pattern = null;
        $count = 0;
        if (\count($frame->calledArgs) >= 4) {
            $pattern = $this->stringArg($frame->calledArgs[3], 'Redis::sScan', 2, 'pattern');
        }
        if (\count($frame->calledArgs) >= 5) {
            $count = $this->intArg($frame->calledArgs[4], 'Redis::sScan', 3, 'count');
        }
        $this->runScan($frame, $receiver, 'Redis::sScan', 'SSCAN', $key, $iteratorVar, $pattern, $count, 'list');
    }
}

final class RedisZScan extends RedisScan
{
    public function __construct()
    {
        parent::__construct();
        $this->name = 'zscan';
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::zScan()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::zScan() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::zScan', 0, 'key');
        $iteratorVar = $frame->calledArgs[2];
        $pattern = null;
        $count = 0;
        if (\count($frame->calledArgs) >= 4) {
            $pattern = $this->stringArg($frame->calledArgs[3], 'Redis::zScan', 2, 'pattern');
        }
        if (\count($frame->calledArgs) >= 5) {
            $count = $this->intArg($frame->calledArgs[4], 'Redis::zScan', 3, 'count');
        }
        $this->runScan($frame, $receiver, 'Redis::zScan', 'ZSCAN', $key, $iteratorVar, $pattern, $count, 'zmap');
    }
}

final class RedisXAdd extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('xadd');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::xAdd()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'Redis::xAdd() expects at least 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::xAdd', 0, 'key');
        $id = $this->stringArg($frame->calledArgs[2], 'Redis::xAdd', 1, 'id');
        $fields = VmRedis::coerceStringMapArg($frame->calledArgs[3], 'Redis::xAdd', 2, 'values');
        $maxlen = 0;
        $approx = false;
        if (\count($frame->calledArgs) >= 5) {
            $maxlen = $this->intArg($frame->calledArgs[4], 'Redis::xAdd', 3, 'maxlen');
        }
        if (\count($frame->calledArgs) >= 6) {
            $opt = $frame->calledArgs[5]->resolveIndirect();
            $approx = Variable::TYPE_BOOLEAN === $opt->type ? $opt->toBool() : ($opt->toInt() !== 0);
        }
        $args = ['XADD', $key];
        if ($maxlen > 0) {
            $args[] = 'MAXLEN';
            if ($approx) {
                $args[] = '~';
            }
            $args[] = (string) $maxlen;
        }
        $args[] = $id;
        foreach ($fields as $f => $v) {
            $args[] = $f;
            $args[] = $v;
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::xAdd', $args);
        if ($handled) {
            return;
        }
        if (!\is_string($reply)) {
            throw new \RedisException('XADD failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->string($reply);
        }
    }
}

final class RedisXRead extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('xread');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::xRead()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::xRead() expects at least 1 argument, 0 given');
        }
        $streams = VmRedis::coerceStringMapArg($frame->calledArgs[1], 'Redis::xRead', 0, 'streams');
        if ([] === $streams) {
            throw new \RedisException('Redis::xRead(): streams map must not be empty');
        }
        $count = -1;
        $block = -1;
        if (\count($frame->calledArgs) >= 3) {
            $count = $this->intArg($frame->calledArgs[2], 'Redis::xRead', 1, 'count');
        }
        if (\count($frame->calledArgs) >= 4) {
            $block = $this->intArg($frame->calledArgs[3], 'Redis::xRead', 2, 'block');
        }
        $args = ['XREAD'];
        if ($count > 0) {
            $args[] = 'COUNT';
            $args[] = (string) $count;
        }
        if ($block >= 0) {
            $args[] = 'BLOCK';
            $args[] = (string) $block;
        }
        $args[] = 'STREAMS';
        $ids = [];
        foreach ($streams as $stream => $id) {
            $args[] = $stream;
            $ids[] = $id;
        }
        foreach ($ids as $id) {
            $args[] = $id;
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::xRead', $args);
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
        $frame->returnVar->copyFrom(VmRedis::replyToVariable($reply));
    }
}

final class RedisXGroup extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('xgroup');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::xGroup()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'Redis::xGroup() expects at least 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $operation = $this->stringArg($frame->calledArgs[1], 'Redis::xGroup', 0, 'operation');
        $key = $this->stringArg($frame->calledArgs[2], 'Redis::xGroup', 1, 'key');
        $group = $this->stringArg($frame->calledArgs[3], 'Redis::xGroup', 2, 'group');
        $id = '$';
        $mkstream = false;
        if (\count($frame->calledArgs) >= 5) {
            $id = $this->stringArg($frame->calledArgs[4], 'Redis::xGroup', 3, 'id');
        }
        if (\count($frame->calledArgs) >= 6) {
            $opt = $frame->calledArgs[5]->resolveIndirect();
            $mkstream = Variable::TYPE_BOOLEAN === $opt->type ? $opt->toBool() : ($opt->toInt() !== 0);
        }
        $args = ['XGROUP', \strtoupper($operation), $key, $group];
        $opUpper = \strtoupper($operation);
        if (\in_array($opUpper, ['CREATE', 'SETID'], true)) {
            $args[] = $id;
        }
        if ('CREATE' === $opUpper && $mkstream) {
            $args[] = 'MKSTREAM';
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::xGroup', $args);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmRedis::replyToVariable($reply));
    }
}

final class RedisPConnect extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('pconnect');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::pconnect()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::pconnect() expects at least 1 argument, 0 given');
        }
        $host = $this->stringArg($frame->calledArgs[1], 'Redis::pconnect', 0, 'host');
        $port = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'Redis::pconnect', 1, 'port', 6379)
            : 6379;
        $timeout = \count($frame->calledArgs) >= 4
            ? $this->floatArg($frame->calledArgs[3], 'Redis::pconnect', 2, 'timeout', 0.0)
            : 0.0;

        $state = VmRedis::state($receiver);
        if ($state->connected && null !== $state->socket) {
            VmRedisNative::close($state->socket);
            $state->socket = null;
            $state->connected = false;
        }

        try {
            $socket = VmRedisNative::pconnect($host, $port, $timeout);
        } catch (\RedisException $e) {
            VmRedis::noteError($receiver, $e->getMessage());
            throw $e;
        }
        $state->socket = $socket;
        $state->connected = true;
        $state->host = $host;
        $state->port = $port;
        $state->timeout = $timeout;
        $state->persistentId = \sprintf('%s:%d', $host, $port);
        $state->dbNum = 0;
        $state->auth = null;
        $state->lastError = null;

        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class RedisRawCommand extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('rawcommand');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::rawCommand()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::rawCommand() expects at least 1 argument, 0 given');
        }
        $args = [];
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            $args[] = VmRedis::coerceValueToString($frame->calledArgs[$i], 'Redis::rawCommand', $i - 1, 'arg');
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::rawCommand', $args);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->copyFrom(VmRedis::replyToVariable($reply));
    }
}

final class RedisSetEx extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('setex');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::setEx()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'Redis::setEx() expects exactly 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::setEx', 0, 'key');
        $expire = $this->intArg($frame->calledArgs[2], 'Redis::setEx', 1, 'expire');
        $value = VmRedis::coerceValueToString($frame->calledArgs[3], 'Redis::setEx', 2, 'value');
        [$handled, $reply] = $this->commandOrQueue(
            $frame,
            $receiver,
            'Redis::setEx',
            ['SETEX', $key, (string) $expire, $value]
        );
        if ($handled) {
            return;
        }
        $ok = (\is_string($reply) && 'OK' === $reply) || true === $reply;
        if (!$ok) {
            throw new \RedisException('SETEX failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class RedisSetNx extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('setnx');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::setNx()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::setNx() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::setNx', 0, 'key');
        $value = VmRedis::coerceValueToString($frame->calledArgs[2], 'Redis::setNx', 1, 'value');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::setNx', ['SETNX', $key, $value]);
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            throw new \RedisException('SETNX failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($reply > 0);
        }
    }
}

class RedisBlPop extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('blpop');
    }

    public function execute(Frame $frame): void
    {
        $this->runBlockingPop($frame, 'Redis::blPop', 'BLPOP');
    }

    protected function runBlockingPop(Frame $frame, string $label, string $op): void
    {
        $receiver = $this->receiver($frame, $label.'()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                $label.'() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $first = $frame->calledArgs[1]->resolveIndirect();
        if (Variable::TYPE_ARRAY === $first->type) {
            $keys = VmRedis::coerceStringListArg($frame->calledArgs[1], $label, 0, 'keys');
            $timeout = $this->intArg($frame->calledArgs[2], $label, 1, 'timeout');
        } else {
            $keys = [];
            $last = \count($frame->calledArgs) - 1;
            for ($i = 1; $i < $last; ++$i) {
                $keys[] = $this->stringArg($frame->calledArgs[$i], $label, $i - 1, 'key');
            }
            $timeout = $this->intArg($frame->calledArgs[$last], $label, $last - 1, 'timeout');
        }
        if ([] === $keys) {
            throw new \RedisException($label.'(): At least one key is required');
        }
        [$handled, $reply] = $this->commandOrQueue(
            $frame,
            $receiver,
            $label,
            \array_merge([$op], $keys, [(string) $timeout])
        );
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
        if (!\is_array($reply)) {
            throw new \RedisException($op.' failed');
        }
        $list = [];
        foreach ($reply as $item) {
            $list[] = null === $item ? '' : (string) $item;
        }
        $frame->returnVar->array(VmRedis::stringListToHashTable($list));
    }
}

final class RedisBrPop extends RedisBlPop
{
    public function __construct()
    {
        parent::__construct();
        $this->name = 'brpop';
    }

    public function execute(Frame $frame): void
    {
        $this->runBlockingPop($frame, 'Redis::brPop', 'BRPOP');
    }
}

final class RedisInfo extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('info');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::info()');
        $args = ['INFO'];
        if (\count($frame->calledArgs) >= 2) {
            $args[] = $this->stringArg($frame->calledArgs[1], 'Redis::info', 0, 'section');
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::info', $args);
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!\is_string($reply)) {
            throw new \RedisException('INFO failed');
        }
        $frame->returnVar->array(VmRedis::stringMapToHashTable(self::parseInfo($reply)));
    }

    /** @return array<string, string> */
    private static function parseInfo(string $raw): array
    {
        $out = [];
        foreach (\preg_split("/\r\n|\n|\r/", $raw) as $line) {
            if ('' === $line || '#' === $line[0]) {
                continue;
            }
            $pos = \strpos($line, ':');
            if (false === $pos) {
                continue;
            }
            $out[\substr($line, 0, $pos)] = \substr($line, $pos + 1);
        }

        return $out;
    }
}

final class RedisFlushAll extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('flushall');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::flushAll()');
        $args = ['FLUSHALL'];
        if (\count($frame->calledArgs) >= 2) {
            $opt = $frame->calledArgs[1]->resolveIndirect();
            $async = false;
            if (Variable::TYPE_BOOLEAN === $opt->type) {
                $async = $opt->toBool();
            } elseif (Variable::TYPE_INTEGER === $opt->type) {
                $async = $opt->toInt() !== 0;
            } elseif (Variable::TYPE_STRING === $opt->type) {
                $async = 'ASYNC' === \strtoupper($opt->toString());
            }
            if ($async) {
                $args[] = 'ASYNC';
            }
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::flushAll', $args);
        if ($handled) {
            return;
        }
        $ok = (\is_string($reply) && 'OK' === $reply) || true === $reply;
        if (!$ok) {
            throw new \RedisException('FLUSHALL failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class RedisWatch extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('watch');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::watch()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::watch() expects at least 1 argument, 0 given');
        }
        $keys = [];
        for ($i = 1; $i < \count($frame->calledArgs); ++$i) {
            $keys[] = $this->stringArg($frame->calledArgs[$i], 'Redis::watch', $i - 1, 'key');
        }
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::watch', \array_merge(['WATCH'], $keys));
        if ($handled) {
            return;
        }
        $ok = (\is_string($reply) && 'OK' === $reply) || true === $reply;
        if (!$ok) {
            throw new \RedisException('WATCH failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class RedisUnwatch extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('unwatch');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::unwatch()');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::unwatch', ['UNWATCH']);
        if ($handled) {
            return;
        }
        $ok = (\is_string($reply) && 'OK' === $reply) || true === $reply;
        if (!$ok) {
            throw new \RedisException('UNWATCH failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
