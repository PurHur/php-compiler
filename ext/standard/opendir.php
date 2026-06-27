<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
use PHPLLVM\Value;

/** opendir() — VM via VmDir; JIT/AOT via __compiler_opendir (issue #3235). */
final class opendir extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('opendir() requires exactly one argument in this compiler build');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'opendir', 'directory', 0, $frame);
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'opendir', 0, 'directory');
        if (null === $frame->returnVar) {
            return;
        }
        $handle = VmDir::opendir($path);
        if (false === $handle) {
            VmFilestatFailure::warnOpendirFailed($frame, $path);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->dirHandle($handle, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('opendir() requires exactly one argument in this compiler build');
        }
        \PHPCompiler\JIT\Builtin\StringDir::ensureLinked($context);

        return JitOpendir::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'opendir', 0, 'directory')
        );
    }
}
