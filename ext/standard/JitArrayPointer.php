<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** JIT stubs for array pointer builtins (#4967 — VM path first). */
final class JitArrayPointer
{
    public static function unsupported(Context $context, string $fn): Value
    {
        throw new \LogicException(
            \sprintf('%s() is not implemented for JIT in this compiler build (#4967)', $fn)
        );
    }
}
