<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
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
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $secondsVar = $frame->calledArgs[1]->resolveIndirect();
        $microseconds = 0;
        if (3 === $argc) {
            $usecVar = $frame->calledArgs[2]->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $usecVar->type) {
                throw new \LogicException('stream_set_timeout() microseconds must be an integer in this compiler build');
            }
            $microseconds = $usecVar->toInt();
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_INTEGER !== $handleVar->type || Variable::TYPE_INTEGER !== $secondsVar->type) {
            throw new \LogicException('stream_set_timeout() stream and seconds must be integers in this compiler build');
        }
        $frame->returnVar->bool(VmFs::streamSetTimeout($handleVar->toInt(), $secondsVar->toInt(), $microseconds));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            throw new \LogicException('stream_set_timeout() requires two or three arguments in this compiler build');
        }
        $usec = 3 === $argc
            ? $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[2], 'stream_set_timeout() microseconds'),
                $context->getTypeFromString('int64')
            )
            : $context->getTypeFromString('int64')->constInt(0, false);

        return JitStreamSetTimeout::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_set_timeout() stream'),
                $context->getTypeFromString('int64')
            ),
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'stream_set_timeout() seconds'),
                $context->getTypeFromString('int64')
            ),
            $usec
        );
    }
}
