<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** filesize() — VM via stat; JIT/AOT via libc stat st_size. */
final class filesize extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / file.stub.php — exactly 1 (#30545).
        $this->requireExactArgCount($frame, 'filesize', 1);
        $filenameArg = $frame->calledArgs[0];
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'filesize');
        $size = VmFs::fileSize($path);
        if (false === $size) {
            VmFilestatArg::warnPathStatFailedForFilenameArg($frame, $filenameArg, 'filesize', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($size);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30545 / peer #30544).
        if (!$this->requireExactJitArgCount($context, $args, 'filesize', 1)) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'filesize');

        return JitFilesize::invoke($context, $path);
    }
}
