<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;

/** Redis::get(string $key) — VM (#6098 / #20612 multi-aware). */
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
        [$handled, $reply] = $this->commandOrQueue($frame, $receiver, 'Redis::get', ['GET', $key]);
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
            throw new \RedisException('GET failed');
        }
        $frame->returnVar->string($reply);
    }
}
