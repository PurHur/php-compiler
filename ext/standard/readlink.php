<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** readlink() — VM via VmFs; JIT/AOT via libc readlink(2). */
final class readlink extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('readlink() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'readlink', 0, 'path');
        if (null === $frame->returnVar) {
            return;
        }
        $target = VmFs::readlink($path);
        if (false === $target) {
            VmFilestatFailure::warnNoSuchFile($frame, 'readlink');
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($target);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('readlink() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'readlink', 0, 'path');

        return JitReadlink::invoke($context, $path);
    }
}
