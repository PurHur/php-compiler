<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM bodies for str_getcsv / fgetcsv CSV parsing.
 *
 * Lowers from {@see StringStrGetcsvJit}, {@see StringFgetcsvJit}, and
 * {@see \PHPCompiler\ext\standard\VmCsv} (#5288, #6750).
 */
final class StringStreamCsv
{
    public static function ensureLinked(Context $context): void
    {
        StringStrGetcsvJit::implement($context);
        StringFgetcsvJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
