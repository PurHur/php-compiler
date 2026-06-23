<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fileatime() — VM via stat; JIT/AOT via libc stat st_atim (php-src ext/standard/filestat.c). */
final class fileatime extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fileatime() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'fileatime', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $atime = VmFs::fileAtime($path);
        if (false === $atime) {
            VmFilestatFailure::warnPathStatFailed($frame, 'fileatime', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($atime);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fileatime() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'fileatime', 0, 'filename');

        return JitFileatime::invoke($context, $path);
    }
}
