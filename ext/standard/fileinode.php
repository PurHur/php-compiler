<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fileinode() — VM via stat; JIT/AOT via libc stat st_ino (php-src ext/standard/filestat.c). */
final class fileinode extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / basic_functions.stub.php — exactly 1 (#30554).
        $this->requireExactArgCount($frame, 'fileinode', 1);
        $filenameArg = $frame->calledArgs[0];
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'fileinode');
        if (null === $frame->returnVar) {
            return;
        }
        $inode = VmFs::fileInode($path);
        if (false === $inode) {
            VmFilestatArg::warnPathStatFailedForFilenameArg($frame, $filenameArg, 'fileinode', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($inode);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30554 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'fileinode', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'fileinode');

        return JitFileinode::invoke($context, $path);
    }
}
