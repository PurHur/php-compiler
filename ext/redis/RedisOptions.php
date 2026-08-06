<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Redis::setOption() / getOption() — pecl-redis redis.c (#28099).
 *
 * Stores OPT_SERIALIZER / OPT_PREFIX / OPT_READ_TIMEOUT / OPT_TCP_KEEPALIVE on
 * {@see RedisState} for round-trip parity; key prefix / serialize apply in follow-ups.
 */
final class RedisSetOption extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('setOption');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::setOption()');
        if (\count($frame->calledArgs) < 3) {
            throw new \ArgumentCountError(
                'Redis::setOption() expects exactly 2 arguments, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $option = $this->intArg($frame->calledArgs[1], 'Redis::setOption', 0, 'option');
        $ok = $this->applyOption($receiver, $option, $frame->calledArgs[2]);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool($ok);
        }
    }

    private function applyOption(ObjectEntry $receiver, int $option, Variable $valueVar): bool
    {
        $state = VmRedis::state($receiver);
        switch ($option) {
            case RedisConstants::OPT_SERIALIZER:
                $serializer = $this->intArg($valueVar, 'Redis::setOption', 1, 'value');
                if (
                    RedisConstants::SERIALIZER_NONE !== $serializer
                    && RedisConstants::SERIALIZER_PHP !== $serializer
                ) {
                    return false;
                }
                $state->serializer = $serializer;

                return true;
            case RedisConstants::OPT_PREFIX:
                $state->prefix = $this->stringArg($valueVar, 'Redis::setOption', 1, 'value');

                return true;
            case RedisConstants::OPT_READ_TIMEOUT:
                $state->readTimeout = $this->floatArg($valueVar, 'Redis::setOption', 1, 'value');
                if (null !== $state->socket) {
                    $secs = (int) \max(1, (int) \ceil($state->readTimeout > 0.0 ? $state->readTimeout : 60.0));
                    \stream_set_timeout($state->socket, $secs);
                }

                return true;
            case RedisConstants::OPT_TCP_KEEPALIVE:
                $state->tcpKeepalive = $this->intArg($valueVar, 'Redis::setOption', 1, 'value');

                return true;
            default:
                return false;
        }
    }
}

final class RedisGetOption extends RedisClassMethod
{
    public function __construct()
    {
        parent::__construct('getOption');
    }

    public function execute(Frame $frame): void
    {
        $receiver = $this->receiver($frame, 'Redis::getOption()');
        if (\count($frame->calledArgs) < 2) {
            throw new \ArgumentCountError(
                'Redis::getOption() expects exactly 1 argument, '.(\count($frame->calledArgs) - 1).' given'
            );
        }
        $option = $this->intArg($frame->calledArgs[1], 'Redis::getOption', 0, 'option');
        $state = VmRedis::state($receiver);
        if (null === $frame->returnVar) {
            return;
        }
        switch ($option) {
            case RedisConstants::OPT_SERIALIZER:
                $frame->returnVar->int($state->serializer);

                return;
            case RedisConstants::OPT_PREFIX:
                $frame->returnVar->string($state->prefix);

                return;
            case RedisConstants::OPT_READ_TIMEOUT:
                $frame->returnVar->float($state->readTimeout);

                return;
            case RedisConstants::OPT_TCP_KEEPALIVE:
                $frame->returnVar->int($state->tcpKeepalive);

                return;
            default:
                $frame->returnVar->bool(false);
        }
    }
}
