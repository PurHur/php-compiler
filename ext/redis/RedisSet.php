<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;

/** Redis::set(string $key, mixed $value) — VM (#6098). */
final class RedisSet extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('set');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::set()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::set() expects at least 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $key = $this->stringArg($frame->calledArgs[1], 'Redis::set', 0, 'key');
        $value = VmRedis::coerceValueToString($frame->calledArgs[2], 'Redis::set', 1, 'value');
        $socket = VmRedis::requireSocket($receiver, 'Redis::set');
        $ok = VmRedisNative::set($socket, $key, $value);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }
}
