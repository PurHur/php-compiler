<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;

/**
 * Redis::connect(string $host, int $port = 6379, float $timeout = 0.0) — VM (#6098).
 *
 * Connection failure throws {@see \RedisException} (phpredis OPT_THROW_ON_ERROR-style subset).
 */
final class RedisConnect extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('connect');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::connect()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError('Redis::connect() expects at least 1 argument, 0 given');
        }
        $host = $this->stringArg($frame->calledArgs[1], 'Redis::connect', 0, 'host');
        $port = \count($frame->calledArgs) >= 3
            ? $this->intArg($frame->calledArgs[2], 'Redis::connect', 1, 'port', 6379)
            : 6379;
        $timeout = \count($frame->calledArgs) >= 4
            ? $this->floatArg($frame->calledArgs[3], 'Redis::connect', 2, 'timeout', 0.0)
            : 0.0;

        $state = VmRedis::state($receiver);
        if ($state->connected && null !== $state->socket) {
            VmRedisNative::close($state->socket);
            $state->socket = null;
            $state->connected = false;
        }

        try {
            $socket = VmRedisNative::connect($host, $port, $timeout);
        } catch (\RedisException $e) {
            VmRedis::noteError($receiver, $e->getMessage());
            throw $e;
        }
        $state->socket = $socket;
        $state->connected = true;
        $state->host = $host;
        $state->port = $port;
        $state->timeout = $timeout;
        $state->persistentId = null;
        $state->dbNum = 0;
        $state->auth = null;
        $state->lastError = null;

        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }
}
