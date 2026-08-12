<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** filectime() — VM via stat; JIT/AOT via libc stat st_ctim (php-src ext/standard/filestat.c). */
final class filectime extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / file.stub.php — exactly 1 (#30545).
        $this->requireExactArgCount($frame, 'filectime', 1);
        $filenameArg = $frame->calledArgs[0];
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'filectime');
        if (null === $frame->returnVar) {
            return;
        }
        $ctime = VmFs::fileCtime($path);
        if (false === $ctime) {
            VmFilestatArg::warnPathStatFailedForFilenameArg($frame, $filenameArg, 'filectime', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($ctime);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30545 / peer #30544).
        if (!$this->requireExactJitArgCount($context, $args, 'filectime', 1)) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'filectime');

        return JitFilectime::invoke($context, $path);
    }
}
