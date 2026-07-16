<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;

/** Redis::close() — VM (#6098). */
final class RedisClose extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('close');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::close()');
        $state = VmRedis::state($receiver);
        if (null !== $state->socket) {
            VmRedisNative::close($state->socket);
            $state->socket = null;
        }
        $state->connected = false;
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
