<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** JIT LLVM bodies for proc_open()/proc_close()/proc_get_status()/proc_terminate() (php-src ext/standard/proc_open.c; #6904, #3740). */
final class ProcessOpen
{
    public static function ensureLinked(Context $context): void
    {
        ProcessOpenJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
