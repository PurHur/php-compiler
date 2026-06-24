<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for str_getcsv / fgetcsv CSV parsing (#5288, #6750, #9444).
 *
 * Lowers from {@see StringStrGetcsv}, {@see StringFgetcsvJit}, and
 * {@see \PHPCompiler\ext\standard\VmCsv}.
 */
final class StringStreamCsv
{
    public static function ensureLinked(Context $context): void
    {
        StringStrGetcsv::ensureLinked($context);
        StringFgetcsvJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
