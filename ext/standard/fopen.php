<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** fopen() — VM via VmFs; JIT/AOT via __compiler_fopen (issue #1117). */
final class fopen extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('fopen() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $path = VmFilestatArg::coerceFilenameArg($frame->calledArgs[0], 'fopen');
        $mode = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'fopen', 1, 'mode');
        $handle = VmFs::fopen($path, $mode, $frame->vmContext);
        if (false === $handle) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'fopen', $path);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('fopen() requires exactly two arguments in this compiler build');
        }

        return JitFopen::invoke(
            $context,
            JitFilestatArg::lowerFilename($context, $args[0], 'fopen'),
            JitStringBuiltinArg::lower($context, $args[1], 'fopen', 1, 'mode')
        );
    }
}
