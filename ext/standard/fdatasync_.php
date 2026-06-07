<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fdatasync() — VM via VmFs; JIT/AOT via __compiler_fdatasync (issue #6813, ext/standard/streamsfuncs.c). */
final class fdatasync_ extends Internal
{
    public function __construct()
    {
        parent::__construct('fdatasync');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fdatasync() requires exactly one argument in this compiler build');
        }
        $handleVar = $frame->calledArgs[0]->resolveIndirect();
        $handle = VmStreamArg::requireStreamHandle($handleVar, 'fdatasync');
        if (null === $frame->returnVar) {
            return;
        }
        if (!VmStreamSync::isSupported($handle)) {
            VmStreamSync::triggerUnsyncableWarning($frame, 'fdatasync');
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->bool(VmFs::fdatasync($handle));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fdatasync() requires exactly one argument in this compiler build');
        }

        return JitFdatasync::invoke(
            $context,
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[0], 'fdatasync() stream'),
                $context->getTypeFromString('int64')
            )
        );
    }
}
