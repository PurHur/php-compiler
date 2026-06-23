<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** filegroup() — VM via stat; JIT/AOT via libc stat st_gid. php-src: ext/standard/filestat.c */
final class filegroup extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('filegroup() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'filegroup', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $gid = VmFs::fileGroup($path);
        if (false === $gid) {
            VmFilestatFailure::warnPathStatFailed($frame, 'filegroup', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($gid);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('filegroup() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'filegroup', 0, 'filename');

        return JitFilegroup::invoke($context, $path);
    }
}
