<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** is_uploaded_file() — VM via VmFs; JIT/AOT via UploadTempJit (issue #5346). */
final class is_uploaded_file extends Internal
{
    public function __construct()
    {
        parent::__construct('is_uploaded_file');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('is_uploaded_file() requires exactly one argument in this compiler build');
        }
        $path = VmString::coerceStringBuiltinArg($frame->calledArgs[0], 'is_uploaded_file', 0, 'filename');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::isValidUploadTempPath($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('is_uploaded_file() requires exactly one argument in this compiler build');
        }
        $path = JitStringBuiltinArg::lower($context, $args[0], 'is_uploaded_file', 0, 'filename');

        return JitIsUploadedFile::invoke($context, $path);
    }
}
