<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;

/** Redis::get(string $key) — VM (#6098). */
final class RedisGet extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('get');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::get()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::get() expects at least 1 argument, 0 given');
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::get', 0, 'key');
        $socket = VmRedis::requireSocket($receiver, 'Redis::get');
        $value = VmRedisNative::get($socket, $key);
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
