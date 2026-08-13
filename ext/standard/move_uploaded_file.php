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
        // php-src file.c / basic_functions.stub.php — exactly 2 (#30553).
        $this->requireExactArgCount($frame, 'move_uploaded_file', 2);
        if (null === $frame->returnVar) {
            return;
        }
        $from = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'move_uploaded_file', 0, 'from');
        $to = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'move_uploaded_file', 1, 'to');
        $frame->returnVar->bool(VmFs::moveUploadedFile($from, $to));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30553 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'move_uploaded_file', 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $a = JitStringBuiltinArg::lower($context, $args[0], 'move_uploaded_file', 0, 'from');
        $b = JitStringBuiltinArg::lower($context, $args[1], 'move_uploaded_file', 1, 'to');

        return JitMoveUploadedFile::invoke($context, $a, $b);
    }
}
