<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fsync() — VM via VmFs; JIT/AOT via __compiler_fsync (issue #6062, ext/standard/streamsfuncs.c). */
final class fsync_ extends Internal
{
    public function __construct()
    {
        parent::__construct('fsync');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fsync() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fsync');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::fsync($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fsync() requires exactly one argument in this compiler build');
        }

        return JitFsync::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'fsync() stream'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
