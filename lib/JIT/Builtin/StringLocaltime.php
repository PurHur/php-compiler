<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * localtime() helper link — AOT builds HT in {@see \PHPCompiler\ext\standard\JitLocaltime} IR (#33952).
 *
 * NestedJIT / helper-runtime {@see \PHPCompiler\ext\standard\LocaltimeJitHelper} returns a null
 * HashTable* under thin user-script AOT (and previously orphaned `__compiler_time` via bare
 * clearInsertionPosition). Keep this a no-op so Type/String_ init does not pull those units —
 * peer {@see StringGetdate} (#26900). Host SSOT remains LocaltimeJitHelper / VmDate for VM.
 *
 * php-src: ext/standard/datetime.c — PHP_FUNCTION(localtime)
 */
final class StringLocaltime
{
    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        // Intentionally empty — see class docblock (#33952 / #26900).
    }
}
