<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCfg\Operand;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\JIT;
use PHPCompiler\JIT\IncludeHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;

/**
 * JIT/AOT eval() lowering via VmEval SSOT (#4652, #10248).
 *
 * php-src: ext/standard/basic_functions.c — zif_eval / zend_eval_stringl
 */
final class EvalRuntime
{
    public static function compile(
        JIT $jit,
        Function_ $func,
        Block $callerBlock,
        OpCode $op
    ): void {
        $codeOp = $callerBlock->getOperand($op->arg2);
        $resultOp = $callerBlock->getOperand($op->arg1);
        $codeVar = $jit->context->getVariableFromOp($codeOp);
        $literal = JitStringArg::compileTimeLiteral($codeVar);

        if (null !== $literal) {
            $evalBlock = VmEval::tryCompileBlock($jit->context->runtime, $literal);
            if ($evalBlock instanceof Block) {
                IncludeHelper::compileInlinedBlock(
                    $jit,
                    $func,
                    $callerBlock,
                    $evalBlock,
                    $resultOp,
                    true,
                    'c:eval'
                );

                return;
            }
        }

        self::emitFalse($jit, $resultOp);
    }

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
