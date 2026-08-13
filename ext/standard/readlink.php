<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** readlink() — VM via VmFs; JIT/AOT via ReadlinkJitHelper PHP (#15353). */
final class readlink extends Internal
{
    public function execute(Frame $frame): void
    {
        // php-src link.c / basic_functions.stub.php — exactly 1 (#30553).
        $this->requireExactArgCount($frame, 'readlink', 1);
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'readlink', 0, 'path', $frame);
        if (null === $frame->returnVar) {
            return;
        }
        $target = VmFs::readlink($path);
        if (false === $target) {
            if (VmStatPath::exists($path)) {
                VmFilestatFailure::warnInvalidArgument($frame, 'readlink');
            } else {
                VmFilestatFailure::warnNoSuchFile($frame, 'readlink');
            }
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->string($target);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30553 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'readlink', 1)) {
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'readlink', 0, 'path');

        return JitReadlink::invoke($context, $path);
    }
}
