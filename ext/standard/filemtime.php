<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** filemtime() — VM via stat; JIT/AOT via libc stat st_mtime. */
final class filemtime extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('filemtime() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceTypedStringBuiltinArg($frame->calledArgs[0], 'filemtime', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $mtime = VmFs::fileMtime($path);
        if (false === $mtime) {
            VmFilestatFailure::warnPathStatFailed($frame, 'filemtime', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($mtime);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('filemtime() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lowerTypedString($context, $args[0], 'filemtime', 0, 'filename');

        return JitFilemtime::invoke($context, $path);
    }
}
