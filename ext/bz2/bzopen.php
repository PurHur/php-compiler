<?php

declare(strict_types=1);

namespace PHPCompiler\ext\bz2;

use PHPCompiler\ext\standard\VmStreamOpenFailure;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
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
        $this->requireExactArgCount($frame, 'bzopen', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $filename = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'bzopen', 0, 'filename');
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
        throw new \LogicException('bzopen() JIT lowering not implemented — use VM path (#17301)');
    }
}
