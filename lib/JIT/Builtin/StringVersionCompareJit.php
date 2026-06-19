<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** @deprecated compat shim — logic lives in {@see StringVersionCompare} (#9813). */
final class StringVersionCompareJit
{
    public static function implement(Context $context): void
    {
        StringVersionCompare::implement($context);
    }
}
