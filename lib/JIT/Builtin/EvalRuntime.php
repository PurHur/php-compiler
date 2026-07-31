<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCfg\Operand;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\JIT;
use PHPCompiler\JIT\IncludeHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\Runtime;

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
            $callerPath = $callerBlock->scriptPath();
            $callLine = null !== $op->sourceLocation
                ? (int) $op->sourceLocation->startLine
                : 0;
            $callLine = VmEval::evalCallSiteLine($callerPath, $callLine);
            $evalFile = VmEval::zendEvalFilename($callerPath, $callLine);

            // Type/function decls: bin/jit.php VM-lowers via Block::literalEvalSourceNeedsVm
            // (#25535). AOT still lowers TYPE_EVAL here — must not emitFalse without first
            // surfacing CompileFatal (final plain property on reference profile → parsed_ok, #26169).
            if (Block::literalEvalSourceNeedsVm($literal)) {
                self::assertDeclLiteralEvalOrThrow($literal, $evalFile);
                self::emitFalse($jit, $resultOp);

                return;
            }

            // Expression-only literal: inline when compile succeeds (#26032 filename shape).
            $evalBlock = VmEval::tryCompileBlock($jit->context->runtime, $literal, $evalFile);
            if ($evalBlock instanceof Block) {
                $evalBlock->setScriptPath($evalFile);
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

    /**
     * Probe class/function-declaring eval on an isolated Runtime so CompileFatal propagates
     * during AOT/JIT emit without registering decls into the outer compile unit (#26169).
     *
     * {@see Runtime::parseAndCompile()} suppresses stderr for eval()'d filenames (VM prints via
     * raiseEvalCompileFatal). AOT must emit the Zend-shaped line here before rethrowing.
     */
    private static function assertDeclLiteralEvalOrThrow(string $literal, string $evalFile): void
    {
        try {
            VmEval::tryCompileBlockOrThrowCompileFatal(new Runtime(), $literal, $evalFile);
        } catch (CompileFatal $e) {
            if (\defined('STDERR') && \is_resource(STDERR)) {
                fwrite(STDERR, $e->zendStderrLine());
            }
            throw $e;
        }
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
