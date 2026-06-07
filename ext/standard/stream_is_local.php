<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** stream_is_local() — VM via VmFs; JIT/AOT via __compiler_stream_is_local (issue #6173). */
final class stream_is_local extends Internal
{
    public function __construct()
    {
        parent::__construct('stream_is_local');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('stream_is_local() requires exactly one argument in this compiler build');
        }
        $handle = VmStreamArg::requireStreamHandle(
            $frame->calledArgs[0]->resolveIndirect(),
            'stream_is_local'
        );
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::streamIsLocal($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('stream_is_local() requires exactly one argument in this compiler build');
        }

        return JitStreamIsLocal::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'stream_is_local() stream'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
