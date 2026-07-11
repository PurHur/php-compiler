<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\VM\VmValueBoxWriteBool;

/**
 * JIT trampoline for __value__writeBool (#9570).
 *
 * SSOT: {@see \PHPCompiler\VM\VmValueBoxWriteBool}
 */
final class ValueBoxWriteBoolJit
{
    public static function implement(Context $context): void
    {
        VmValueBoxWriteBool::implement($context);
    }
}
