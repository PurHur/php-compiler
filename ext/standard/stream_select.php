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
 * stream_select() — multiplex stream handles (php-src ext/standard/streams.c; #3131, #9216).
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
        if ($argc < 4) {
            throw new \ArgumentCountError(\sprintf(
                'stream_select() expects at least 4 arguments, %d given',
                $argc
            ));
        }
        if ($argc > 5) {
            throw new \ArgumentCountError(\sprintf(
                'stream_select() expects at most 5 arguments, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar) {
            return;
        }

        $readPairs = VmStreamSelect::pairsFromArray($frame->calledArgs[0]);

        $writePairs = null;
        if ($argc >= 2) {
            $writeVar = $frame->calledArgs[1]->resolveIndirect();
            if (Variable::TYPE_NULL !== $writeVar->type) {
                VmStreamSelectGuard::warnUnselectableStreams($frame, $frame->calledArgs[1]);
                $writePairs = VmStreamSelect::pairsFromArray($frame->calledArgs[1]);
            }
        }

        $exceptPairs = null;
        if ($argc >= 3) {
            $exceptVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_NULL !== $exceptVar->type) {
                VmStreamSelectGuard::warnUnselectableStreams($frame, $frame->calledArgs[2]);
                $exceptPairs = VmStreamSelect::pairsFromArray($frame->calledArgs[2]);
            }
        }

        VmStreamSelectGuard::warnUnselectableStreams($frame, $frame->calledArgs[0]);

        $totalPairs = \count($readPairs)
            + \count($writePairs ?? [])
            + \count($exceptPairs ?? []);
        if (0 === $totalPairs) {
            throw new \ValueError('No stream arrays were passed');
        }

        VmStreamSelectGuard::ensureSelectableStreamArrays($readPairs, $writePairs ?? [], $exceptPairs ?? []);

        $seconds = self::requireIntArg($frame->calledArgs[3], 'stream_select', 4, 'seconds');
        $microseconds = 0;
        if ($argc >= 5) {
            $microseconds = self::requireIntArg($frame->calledArgs[4], 'stream_select', 5, 'microseconds');
        }

        $ready = VmStreamSelect::multiplex($readPairs, $writePairs, $exceptPairs, $seconds, $microseconds);
        if (false === $ready) {
            $frame->returnVar->bool(false);

            return;
        }

        VmStreamSelect::writeBackStreamArray(
            $frame->calledArgs[0],
            self::handlesFromPairs($readPairs),
            $frame->vmContext
        );
        if (null !== $writePairs) {
            VmStreamSelect::writeBackStreamArray(
                $frame->calledArgs[1],
                self::handlesFromPairs($writePairs),
                $frame->vmContext
            );
        }
        if (null !== $exceptPairs) {
            VmStreamSelect::writeBackStreamArray(
                $frame->calledArgs[2],
                self::handlesFromPairs($exceptPairs),
                $frame->vmContext
            );
        }

        $frame->returnVar->int($ready);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('stream_select() is VM-only in this compiler build (issue #3131)');
    }

    /**
     * @param list<StreamSelectPair> $pairs
     *
     * @return list<int>
     */
    private static function handlesFromPairs(array $pairs): array
    {
        $handles = [];
        foreach ($pairs as $pair) {
            $handles[] = $pair->handle;
        }

        return $handles;
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
