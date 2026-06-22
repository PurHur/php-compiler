<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** filesize() — VM via stat; JIT/AOT via libc stat st_size. */
final class filesize extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('filesize() requires exactly one argument in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'filesize');
        $size = VmFs::fileSize($path);
        if (false === $size) {
            VmFilestatFailure::warnPathStatFailed($frame, 'filesize', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($size);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('filesize() requires exactly one argument in this compiler build');
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'filesize');

        return JitFilesize::invoke($context, $path);
    }
}
