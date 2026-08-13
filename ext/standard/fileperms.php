<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fileperms() — VM via stat; JIT/AOT via libc stat st_mode. */
final class fileperms extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / basic_functions.stub.php — exactly 1 (#30554).
        $this->requireExactArgCount($frame, 'fileperms', 1);
        $filenameArg = $frame->calledArgs[0];
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'fileperms');
        if (null === $frame->returnVar) {
            return;
        }
        $mode = VmFs::filePerms($path);
        if (false === $mode) {
            VmFilestatArg::warnPathStatFailedForFilenameArg($frame, $filenameArg, 'fileperms', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($mode);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30554 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'fileperms', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'fileperms');

        return JitFileperms::invoke($context, $path);
    }
}
