<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\JIT;
use PHPCompiler\OpCode;
use PHPCompiler\Runtime;

/**
 * JIT/AOT lowering for eval() / TYPE_EVAL (issue #4652).
 *
 * php-src: ext/standard/basic_functions.c — zif_eval / zend_eval_stringl
 */
final class EvalHelper
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
            $evalBlock = self::tryCompileEvalLiteral($jit->context->runtime, $literal);
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

        Builtin\EvalRuntime::emitFalse($jit, $resultOp);
    }

    private static function tryCompileEvalLiteral(Runtime $runtime, string $code): ?Block
    {
        Runtime::clearLastParseFailure();
        $runtime->compiler->resetCompileAbortDetail();
        $wrapped = VmEval::wrapEvalCode($code);

        try {
            $block = $runtime->parseAndCompile($wrapped, VmEval::EVAL_FILENAME);
        } catch (\CompileError) {
            return null;
        } catch (\Throwable) {
            return null;
        }

        return $block;
    }
}
