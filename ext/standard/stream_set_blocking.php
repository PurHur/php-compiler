<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\InternalStrictArg as JitInternalStrictArg;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/**
 * stream_set_blocking() — toggle blocking mode on stream resources (issue #6007).
 *
 * php-src: ext/standard/streams.c — PHP_FUNCTION(stream_set_blocking)
 */
final class stream_set_blocking extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_set_blocking');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_set_blocking() requires exactly two arguments in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_set_blocking',
            1
        );
        if (InternalStrictArg::isCallerStrict($frame)) {
            $mode = InternalStrictArg::requireBool($frame, 1, 'stream_set_blocking', 'mode')->toBool();
        } else {
            $mode = VmMath::parseBoolBuiltinArg(
                $frame->calledArgs[1]->resolveIndirect(),
                'stream_set_blocking',
                2,
                'mode'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::streamSetBlocking($handle, $mode));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('stream_set_blocking() requires exactly two arguments in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        JitInternalStrictArg::requireBool($context, $args[1], 'stream_set_blocking', 'mode', 2);
        $mode = $context->builder->zExt(
            JitBoolArg::lower($context, $args[1], 'stream_set_blocking(): Argument #2 ($mode)'),
            $i64
        );

        return JitStreamSetBlocking::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_set_blocking() stream'),
                $i64
            ),
            $mode
        );
    }
}
