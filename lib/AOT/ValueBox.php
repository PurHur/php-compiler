<?php

declare(strict_types=1);

namespace PHPCompiler\AOT;

use PHPCompiler\JIT\Builtin\ValueBoxWriteBoolJit;
use PHPCompiler\JIT\Context;

/**
 * PHP-owned {@see __value__} box writers for AOT/JIT link (#5480).
 *
 * Replaces hand-written C in lib/AOT/runtime/phpc_value_box.c.
 */
final class ValueBox
{
    public static function ensureLinked(Context $context): void
    {
        ValueBoxWriteBoolJit::implement($context);
    }
}
