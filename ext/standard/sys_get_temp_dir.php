<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** sys_get_temp_dir() — VM via VmSysGetTempDirNative; JIT/AOT via SysGetTempDirRuntime PHP (#9585). */
final class sys_get_temp_dir extends Internal
{
    public function __construct()
    {
        parent::__construct('sys_get_temp_dir');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('sys_get_temp_dir() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->string(VmSysGetTempDirNative::resolve());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'sys_get_temp_dir() expects exactly 0 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        return JitSysGetTempDir::invoke($context);
    }
}
