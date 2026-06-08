<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** move_uploaded_file() — VM via VmFs; JIT/AOT via UploadTempJit (issue #5346). */
final class move_uploaded_file extends Internal
{
    public function __construct()
    {
        parent::__construct('move_uploaded_file');
    }

    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('move_uploaded_file() requires exactly two arguments in this compiler build');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $from = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'move_uploaded_file', 0, 'from');
        $to = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'move_uploaded_file', 1, 'to');
        $frame->returnVar->bool(VmFs::moveUploadedFile($from, $to));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('move_uploaded_file() requires exactly two arguments in this compiler build');
        }
        $a = JitStringBuiltinArg::lower($context, $args[0], 'move_uploaded_file', 0, 'from');
        $b = JitStringBuiltinArg::lower($context, $args[1], 'move_uploaded_file', 1, 'to');

        return JitMoveUploadedFile::invoke($context, $a, $b);
    }
}
