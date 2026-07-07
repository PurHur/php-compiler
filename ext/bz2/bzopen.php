<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmStreamOpenFailure;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** bzopen() — bzip2 stream open (ext/bz2/bz2.c parity, #17301). */
final class bzopen extends Internal
{
    public function __construct()
    {
        parent::__construct('bzopen');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \LogicException('bzopen() expects exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'bzopen', 0, 'file');
        $mode = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'bzopen', 1, 'mode');
        $handle = VmBz2Stream::bzopen($filename, $mode);
        if (false === $handle) {
            VmStreamOpenFailure::warnFailedToOpen($frame, 'bzopen', $filename);
            $frame->returnVar->bool(false);

            return;
        }
        $frame->returnVar->streamHandle($handle, $frame->vmContext);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('bzopen() expects exactly two arguments in this compiler build');
        }

        return JitBz2open::invoke(
            $context,
            JitStringBuiltinArg::lower($context, $args[0], 'bzopen', 0, 'file'),
            JitStringBuiltinArg::lower($context, $args[1], 'bzopen', 1, 'mode')
        );
    }
}
