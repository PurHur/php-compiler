<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** is_uploaded_file() — VM via VmFs; JIT/AOT via UploadTempJit (issue #5346). Named $filename (#28853). */
final class is_uploaded_file extends Internal
{
    public function __construct()
    {
        parent::__construct('is_uploaded_file');
    }

    public function execute(Frame $frame): void
    {
        // php-src file.c / basic_functions.stub.php — exactly 1 (#30553).
        $this->requireExactArgCount($frame, 'is_uploaded_file', 1);
        $path = VmFilestatArg::filenameArgForFrame($frame, 0, 'is_uploaded_file');
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmFs::isValidUploadTempPath($path));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // Catchable ArgumentCountError under AOT try/catch (#30553 / peer #30551).
        if (!$this->requireExactJitArgCount($context, $args, 'is_uploaded_file', 1)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $path = JitFilestatArg::lowerFilename($context, $args[0], 'is_uploaded_file');

        return JitIsUploadedFile::invoke($context, $path);
    }
}
