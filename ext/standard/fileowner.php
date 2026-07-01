<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fileowner() — VM via stat; JIT/AOT via libc stat st_uid. php-src: ext/standard/filestat.c */
final class fileowner extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('fileowner() requires exactly one argument in this compiler build');
        }
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'fileowner');
        if (null === $frame->returnVar) {
            return;
        }
        $uid = VmFs::fileOwner($path);
        if (false === $uid) {
            VmFilestatFailure::warnPathStatFailed($frame, 'fileowner', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($uid);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('fileowner() requires exactly one argument in this compiler build');
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'fileowner');

        return JitFileowner::invoke($context, $path);
    }
}
