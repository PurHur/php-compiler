<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;

/**
 * Historical StringTrimMode compile-time helper (#7283).
 *
 * php-src never ships StringTrimMode; trim/ltrim/rtrim are arity ≤2 (#28202 / #28230).
 * Kept as a no-op so spine/bootstrap still require this file.
 */
final class StringTrimModeJit
{
    public static function compileTimeModeBitmask(Context $context, JITVariable $arg): ?int
    {
        return null;
    }
}
