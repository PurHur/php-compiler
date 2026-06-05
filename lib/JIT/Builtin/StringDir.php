<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for opendir/readdir/closedir/rewinddir (php-src ext/standard/dir.c; #5494).
 */
final class StringDir
{
    public static function ensureLinked(Context $context): void
    {
        StringDirJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
