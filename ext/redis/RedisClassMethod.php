<?php

declare(strict_types=1);

namespace PHPCompiler\ext\redis;

use PHPCompiler\Frame;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/** Shared VM wiring for ext/redis class methods (PECL phpredis; #6098 / #20612). */
abstract class RedisClassMethod extends VmClassMethod
{
    protected function receiver(Frame $frame, string $label): ObjectEntry
    {
        if (\count($frame->calledArgs) < 1) {
            throw new \LogicException($label.' called without $this');
        }

        return VmRedis::requireReceiver($frame->calledArgs[0], $label);
    }

    protected function stringArg(Variable $var, string $label, int $index, string $paramName): string
    {
        return VmRedis::coerceStringArg($var, $label, $index, $paramName);
    }

    protected function intArg(Variable $var, string $label, int $index, string $paramName, int $default = 0): int
    {
        return VmRedis::coerceIntArg($var, $label, $index, $paramName, $default);
    }

    protected function floatArg(Variable $var, string $label, int $index, string $paramName, float $default = 0.0): float
    {
        return VmRedis::coerceFloatArg($var, $label, $index, $paramName, $default);
    }

    /**
     * Run a RESP command, or queue it when in MULTI/PIPELINE and return $this.
     *
     * @param list<string> $args
     *
     * @return array{0: bool, 1: mixed} [true, null] when return already assigned; else [false, reply]
     */
    protected function commandOrQueue(Frame $frame, ObjectEntry $receiver, string $label, array $args): array
    {
        $state = VmRedis::state($receiver);
        $socket = VmRedis::requireSocket($receiver, $label);
        if (RedisConstants::PIPELINE === $state->mode) {
            VmRedisNative::writeCommand($socket, $args);
            ++$state->pipelinePending;
            if (null !== $frame->returnVar) {
                $frame->returnVar->object($receiver);
            }

            return [true, null];
        }
        if (RedisConstants::MULTI === $state->mode) {
            VmRedisNative::command($socket, $args);
            if (null !== $frame->returnVar) {
                $frame->returnVar->object($receiver);
            }

            return [true, null];
        }

        return [false, VmRedisNative::command($socket, $args)];
    }
}
