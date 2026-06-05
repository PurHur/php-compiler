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

/** stream_supports_lock() — VM via VmFs; JIT/AOT via __compiler_stream_supports (issue #6039). */
final class stream_supports_lock extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_supports_lock');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_supports_lock() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'stream_supports_lock');
        $frame->returnVar->bool(
            VmFs::streamSupports($handle, VmStreamSupports::STREAM_LOCK)
        );
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stream_supports_lock() requires exactly one argument in this compiler build');
        }
        $i64 = $context->getTypeFromString('int64');
        $featureLock = $i64->constInt(VmStreamSupports::STREAM_LOCK, false);

        return JitStreamSupports::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_supports_lock() stream'),
                $i64
            ),
            $featureLock
        );
    }
}
