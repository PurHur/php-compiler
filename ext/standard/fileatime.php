<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fileatime() — VM via stat; JIT/AOT via libc stat st_atim (php-src ext/standard/filestat.c). */
final class fileatime extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / file.stub.php — exactly 1 (#30545).
        $this->requireExactArgCount($frame, 'fileatime', 1);
        $filenameArg = $frame->calledArgs[0];
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'fileatime');
        if (null === $frame->returnVar) {
            return;
        }
        $atime = VmFs::fileAtime($path);
        if (false === $atime) {
            VmFilestatArg::warnPathStatFailedForFilenameArg($frame, $filenameArg, 'fileatime', $path, false);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->int($atime);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30545 / peer #30544).
        if (!$this->requireExactJitArgCount($context, $args, 'fileatime', 1)) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'fileatime');

        return JitFileatime::invoke($context, $path);
    }
}
