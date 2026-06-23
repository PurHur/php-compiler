<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * stream_select() — multiplex stream handles (php-src ext/standard/streams.c; #3131).
 *
 * VM-only v1; JIT/AOT deferred.
 */
final class stream_select extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_select');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 4 || $argc > 5) {
            throw new \LogicException('stream_select() requires four or five arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $readPairs = VmProcess::hostStreamsFromArray($frame->calledArgs[0]);
        $read = array_map(static fn (array $pair): mixed => $pair[1], $readPairs);

        $write = null;
        $writePairs = [];
        if ($argc >= 2) {
            $writeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $writeVar->type) {
                VmStreamSelectGuard::warnUnselectableStreams($frame, $frame->calledArgs[1]);
                $writePairs = VmProcess::hostStreamsFromArray($frame->calledArgs[1]);
                $write = array_map(static fn (array $pair): mixed => $pair[1], $writePairs);
            }
        }

        $except = null;
        $exceptPairs = [];
        if ($argc >= 3) {
            $exceptVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $exceptVar->type) {
                VmStreamSelectGuard::warnUnselectableStreams($frame, $frame->calledArgs[2]);
                $exceptPairs = VmProcess::hostStreamsFromArray($frame->calledArgs[2]);
                $except = array_map(static fn (array $pair): mixed => $pair[1], $exceptPairs);
            }
        }

        VmStreamSelectGuard::warnUnselectableStreams($frame, $frame->calledArgs[0]);
        VmStreamSelectGuard::ensureSelectableStreamArrays($readPairs, $writePairs, $exceptPairs);

        $seconds = self::requireIntArg($frame->calledArgs[3], 'stream_select', 4, 'seconds');
        $microseconds = 0;
        if ($argc >= 5) {
            $microseconds = self::requireIntArg($frame->calledArgs[4], 'stream_select', 5, 'microseconds');
        }

        $ready = VmProcess::streamSelect($read, $write, $except, $seconds, $microseconds);
        if (false === $ready) {
            $frame->returnVar->bool(false);

            return;
        }

        VmProcess::writeBackStreamArray($frame->calledArgs[0], $read, $frame->vmContext);
        if (null !== $write) {
            VmProcess::writeBackStreamArray($frame->calledArgs[1], $write, $frame->vmContext);
        }
        if (null !== $except) {
            VmProcess::writeBackStreamArray($frame->calledArgs[2], $except, $frame->vmContext);
        }

        $frame->returnVar->int($ready);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('stream_select() is VM-only in this compiler build (issue #3131)');
    }

    private static function requireIntArg(Variable $arg, string $functionName, int $argNum, string $paramName): int
    {
        $arg = $arg->resolveIndirect();
        if (Variable::TYPE_NULL === $arg->type && 'seconds' === $paramName) {
            return 0;
        }
        if (Variable::TYPE_INTEGER !== $arg->type) {
            throw new \TypeError(\sprintf(
                '%s(): Argument #%d ($%s) must be of type int, %s given',
                $functionName,
                $argNum,
                $paramName,
                VmStreamArg::debugTypeName($arg)
            ));
        }

        return $arg->toInt();
    }
}
