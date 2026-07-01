<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** filectime() — VM via stat; JIT/AOT via libc stat st_ctim (php-src ext/standard/filestat.c). */
final class filectime extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('filectime() requires exactly one argument in this compiler build');
        }
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'filectime');
        if (null === $frame->returnVar) {
            return;
        }
        $ctime = VmFs::fileCtime($path);
        if (false === $ctime) {
            VmFilestatFailure::warnPathStatFailed($frame, 'filectime', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($ctime);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('filectime() requires exactly one argument in this compiler build');
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'filectime');

        return JitFilectime::invoke($context, $path);
    }
}
