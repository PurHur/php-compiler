<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\OpCode;
use PHPCompiler\VM\VmUnaryMinus;

/**
 * JIT trampoline for unary - lowering (#9976).
 *
 * SSOT: {@see \PHPCompiler\VM\VmUnaryMinus}
 */
final class JitUnaryMinus
{
    public static function lower(Context $context, OpCode $opcode, Variable $var): Variable
    {
        return VmUnaryMinus::lower($context, $opcode, $var);
    }
}
