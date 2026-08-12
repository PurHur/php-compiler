<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** filetype() — VM via lstat; JIT/AOT via libc lstat st_mode. */
final class filetype extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src filestat.c / file.stub.php — exactly 1 (#30545).
        $this->requireExactArgCount($frame, 'filetype', 1);
        $filenameArg = $frame->calledArgs[0];
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'filetype');
        if (null === $frame->returnVar) {
            return;
        }
        $type = VmFs::fileType($path);
        if (false === $type) {
            VmFilestatArg::warnPathStatFailedForFilenameArg($frame, $filenameArg, 'filetype', $path, true);
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($type);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30545 / peer #30544).
        if (!$this->requireExactJitArgCount($context, $args, 'filetype', 1)) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool(
                $context,
                $slot,
                $context->getTypeFromString('int1')->constInt(0, false)
            );

            return JitValueBox::pointer($context, $slot);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'filetype');

        return JitFiletype::invoke($context, $path);
    }
}
