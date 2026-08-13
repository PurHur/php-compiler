<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** filegroup() — VM via stat; JIT/AOT via libc stat st_gid. php-src: ext/standard/filestat.c */
final class filegroup extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / basic_functions.stub.php — exactly 1 (#30554).
        $this->requireExactArgCount($frame, 'filegroup', 1);
        $filenameArg = $frame->calledArgs[0];
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'filegroup');
        if (null === $frame->returnVar) {
            return;
        }
        $gid = VmFs::fileGroup($path);
        if (false === $gid) {
            VmFilestatArg::warnPathStatFailedForFilenameArg($frame, $filenameArg, 'filegroup', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($gid);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30554 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'filegroup', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'filegroup');

        return JitFilegroup::invoke($context, $path);
    }
}
