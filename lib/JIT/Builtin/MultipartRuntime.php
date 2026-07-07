<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT/AOT link for user-script multipart POST populate (#15624).
 *
 * User-script thin AOT uses {@see StringMultipartStandaloneLlvm} (init-safe LLVM, no nested JIT).
 * php-src: main/rfc1867.c
 */
final class MultipartRuntime
{
    public static function ensureUserScriptLinked(Context $context): void
    {
        ParseStrRuntime::ensureUserScriptLinked($context);
        StringMultipartStandaloneLlvm::ensureLinked($context);
    }
}
