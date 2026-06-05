<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for upload temp helpers (issue #5346).
 *
 * Lowers {@see UploadTempJit} from PHP — no phpc_upload_temp.c bitcode link.
 */
final class StringFsDir
{
    public static function ensureLinked(Context $context): void
    {
        UploadTempJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
