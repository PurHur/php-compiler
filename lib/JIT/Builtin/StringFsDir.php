<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for fs-dir runtime, upload temp, and glob/scandir vec helpers (#6982, #5346, #5459).
 */
final class StringFsDir
{
    public static function ensureLinked(Context $context): void
    {
        StringFsDirJit::implement($context);
        StringFsGlobVecJit::implement($context);
        UploadTempJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
