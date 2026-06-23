<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fileperms() — VM via stat; JIT/AOT via libc stat st_mode. */
final class fileperms extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fileperms() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'fileperms', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $mode = VmFs::filePerms($path);
        if (false === $mode) {
            VmFilestatFailure::warnPathStatFailed($frame, 'fileperms', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($mode);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fileperms() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'fileperms', 0, 'filename');

        return JitFileperms::invoke($context, $path);
    }
}
