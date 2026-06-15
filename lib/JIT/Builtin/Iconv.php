<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT/AOT link for __compiler_iconv LLVM runtime (#6009, ext/iconv/iconv.c). */
final class Iconv
{
    public static function ensureLinked(Context $context): void
    {
        IconvRuntime::implement($context);
    }
}
