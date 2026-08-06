<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * Redis persistence / replication-wait admin commands (pecl-redis; #28117).
 *
 * SAVE / BGSAVE / LASTSAVE / WAIT / WAITAOF / BGREWRITEAOF via existing RESP path.
 */
final class RedisSave extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('save');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::save()');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::save', ['SAVE']);
        if ($handled) {
            return;
        }
        $ok = (\is_string($reply) && 'OK' === $reply) || true === $reply;
        if (!$ok) {
            VmRedis::noteError($receiver, 'SAVE failed');
            throw new \RedisException('SAVE failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class RedisBgSave extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('bgsave');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::bgSave()');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::bgSave', ['BGSAVE']);
        if ($handled) {
            return;
        }
        // BGSAVE returns "Background saving started" as simple string, or OK
        $ok = true === $reply
            || (\is_string($reply) && ('OK' === $reply || false !== \stripos($reply, 'saving')));
        if (!$ok) {
            VmRedis::noteError($receiver, 'BGSAVE failed');
            throw new \RedisException('BGSAVE failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}

final class RedisLastSave extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('lastsave');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::lastSave()');
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::lastSave', ['LASTSAVE']);
        if ($handled) {
            return;
        }
        if (!\is_int($reply)) {
            VmRedis::noteError($receiver, 'LASTSAVE failed');
            throw new \RedisException('LASTSAVE failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->int($reply);
        }
    }
}

final class RedisWait extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('wait');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::wait()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::wait() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $numreplicas = $this->intArg($frame->calledArgs[1], 'Redis::wait', 0, 'numreplicas');
        $timeout = $this->intArg($frame->calledArgs[2], 'Redis::wait', 1, 'timeout');
        [$handled, $reply] = $this->commandOrQueue(
            $frame,
            $receiver,
            'Redis::wait',
            ['WAIT', (string) $numreplicas, (string) $timeout]
        );
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (!\is_int($reply)) {
            VmRedis::noteError($receiver, 'WAIT failed');
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->int($reply);
    }
}

final class RedisWaitAof extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('waitaof');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::waitaof()');
        if (\count($frame->calledArgs) < 4) {
            throw new \ArgumentCountError(
                'Redis::waitaof() expects exactly 3 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $numlocal = $this->intArg($frame->calledArgs[1], 'Redis::waitaof', 0, 'numlocal');
        $numreplicas = $this->intArg($frame->calledArgs[2], 'Redis::waitaof', 1, 'numreplicas');
        $timeout = $this->intArg($frame->calledArgs[3], 'Redis::waitaof', 2, 'timeout');
        [$handled, $reply] = $this->commandOrQueue(
            $frame,
            $receiver,
            'Redis::waitaof',
            ['WAITAOF', (string) $numlocal, (string) $numreplicas, (string) $timeout]
        );
        if ($handled) {
            return;
        }
        if (null === $frame->returnVar) {
            return;
        }
        // WAITAOF returns array of two integers [local, replicas] on Redis 7.2+
        if (\is_array($reply) && 2 === \count($reply)) {
            $ht = new HashTable();
            foreach ($reply as $item) {
                $slot = new Variable();
                if (\is_int($item)) {
                    $slot->int($item);
                } else {
                    $slot->string((string) $item);
                }
                $ht->append($slot);
            }
            $frame->returnVar->array($ht);

            return;
        }
        VmRedis::noteError($receiver, 'WAITAOF failed');
        $frame->returnVar->bool(false);
    }
}

final class RedisBgRewriteAof extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('bgrewriteaof');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::bgrewriteaof()');
        [$handled, $reply] = $this->commandOrQueue(
            $frame,
            $receiver,
            'Redis::bgrewriteaof',
            ['BGREWRITEAOF']
        );
        if ($handled) {
            return;
        }
        $ok = true === $reply
            || (\is_string($reply) && ('OK' === $reply || false !== \stripos($reply, 'rewrit')));
        if (!$ok) {
            VmRedis::noteError($receiver, 'BGREWRITEAOF failed');
            throw new \RedisException('BGREWRITEAOF failed');
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
