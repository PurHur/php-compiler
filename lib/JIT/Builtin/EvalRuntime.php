<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCfg\Operand;
use PHPCompiler\JIT;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;

/** eval() fallback: assign false when inline compile fails (#4652). */
final class EvalRuntime
{
    public static function emitFalse(JIT $jit, Operand $resultOp): void
    {
        $context = $jit->context;
        $falseVar = new Variable(
            $context,
            Variable::TYPE_NATIVE_BOOL,
            Variable::KIND_VALUE,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $falseVar->isNullConstant = false;
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $falseVar->value);
        $boxed = new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $jit->assignOperandForced($resultOp, $boxed);
    }
}
