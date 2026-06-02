<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for eval() FuncCall path (issue #4652). */
final class JitEval
{
    public static function invoke(Context $context, JITVariable $codeArg): Value
    {
        $literal = $codeArg->compileTimeString ?? null;
        if (null === $literal && JITVariable::TYPE_STRING === $codeArg->type) {
            $literal = $codeArg->compileTimeString;
        }
        if (null === $literal) {
            throw new \LogicException(
                'eval() code must be a compile-time string literal in this compiler build (issue #4652)'
            );
        }
        $runtime = $context->runtime;
        \PHPCompiler\Runtime::clearLastParseFailure();
        $runtime->compiler->resetCompileAbortDetail();
        try {
            $block = $runtime->parseAndCompile(
                VmEval::wrapEvalCode($literal),
                VmEval::EVAL_FILENAME
            );
        } catch (\Throwable) {
            $block = null;
        }
        if (null === $block) {
            $i1 = $context->getTypeFromString('int1');

            return $context->builder->zExt($i1->constInt(0, false), $context->getTypeFromString('int64'));
        }
        throw new \LogicException(
            'eval() FuncCall with compile-time literal should use TYPE_EVAL inline lowering (issue #4652)'
        );
    }
}
