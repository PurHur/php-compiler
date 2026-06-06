<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_set_timeout() — VM via VmFs; JIT/AOT via __compiler_stream_set_timeout (issue #3754). */
final class stream_set_timeout_ extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_set_timeout');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('stream_set_timeout() requires two or three arguments in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_set_timeout',
            1
        );
        $seconds = VmMath::parseIntBuiltinArg(
            $frame->calledArgs[1]->resolveIndirect(),
            'stream_set_timeout',
            2,
            'seconds'
        );
        $microseconds = 0;
        if (3 === $argc) {
            $microseconds = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2]->resolveIndirect(),
                'stream_set_timeout',
                3,
                'microseconds'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::streamSetTimeout($handle, $seconds, $microseconds));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('stream_set_timeout() requires two or three arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $usec = 3 === $argc
            ? JitSleep::zParamLong($context, $args[2], 'stream_set_timeout', 3, 'microseconds')
            : $i64->constInt(0, false);

        return JitStreamSetTimeout::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_set_timeout() stream'),
                $i64
            ),
            JitSleep::zParamLong($context, $args[1], 'stream_set_timeout', 2, 'seconds'),
            $usec
        );
    }
}
