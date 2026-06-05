<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/**
 * JIT LLVM body for str_getcsv (CSV line parse).
 *
 * Lowers from {@see StringStrGetcsvJit} / {@see \PHPCompiler\ext\standard\VmCsv} (#5288).
 */
final class StringStreamCsv
{
    public static function ensureLinked(Context $context): void
    {
        StringStrGetcsvJit::implement($context);
    }

    public static function implement(Context $context): void
    {
        self::ensureLinked($context);
    }
}
