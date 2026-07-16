<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitUploadTempKernel;
use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for upload temp helpers (#5346, #9799, #19723). */
final class UploadTempJit
{
    public static function implement(Context $context): void
    {
        JitUploadTempKernel::implement($context);
    }
}
