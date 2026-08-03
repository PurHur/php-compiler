<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCfg\Operand;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
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
            // surfacing CompileFatal (final plain property on reference profile → parsed_ok, #26169)
            // or a catchable ParseError for syntax rejects (unclosed class body → ok, #27107).
            if (Block::literalEvalSourceNeedsVm($literal)) {
                self::compileDeclLiteralEval($jit, $resultOp, $literal, $evalFile);

                return;
            }

            // Expression-only literal: inline when compile succeeds (#26032 filename shape).
            $evalBlock = VmEval::tryCompileBlock($jit->context->runtime, $literal, $evalFile);
            if ($evalBlock instanceof Block) {
                $evalBlock->setScriptPath($evalFile);
                \PHPCompiler\JIT\IncludeHelper::compileInlinedBlock(
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

            // Zend eval() throws ParseError on syntax failure — never silently return false (#27107).
            self::emitEvalParseError($jit, $literal, $evalFile, Runtime::getLastParseFailure());

            return;
        }

        self::emitFalse($jit, $resultOp);
    }

    /**
     * Probe class/function-declaring eval on an isolated Runtime so CompileFatal propagates
     * during AOT/JIT emit without registering decls into the outer compile unit (#26169).
     *
     * Syntax rejects become catchable ParseError at runtime (#27107 / VmEval::failEvalParse).
     * Non-syntax CompileFatal (reference-profile rejectors) still abort the AOT build (#26169).
     */
    private static function compileDeclLiteralEval(
        JIT $jit,
        Operand $resultOp,
        string $literal,
        string $evalFile
    ): void {
        try {
            $evalBlock = VmEval::tryCompileBlockOrThrowCompileFatal(new Runtime(), $literal, $evalFile);
        } catch (CompileFatal $e) {
            if (CompileFatal::isSyntaxParseErrorMessage($e->getMessage())) {
                self::emitEvalParseError($jit, $literal, $evalFile, $e->getMessage());

                return;
            }
            // Reference-profile / compile-time fatals: Zend exits uncatchable — abort emit (#26169).
            if (\defined('STDERR') && \is_resource(STDERR)) {
                fwrite(STDERR, $e->zendStderrLine());
            }
            throw $e;
        }

        if (!$evalBlock instanceof Block) {
            self::emitEvalParseError($jit, $literal, $evalFile, Runtime::getLastParseFailure());

            return;
        }

        // Decl succeeded but cannot MCJIT-inline into the outer unit (#25535).
        self::emitFalse($jit, $resultOp);
    }

    /**
     * Emit catchable ParseError matching VmEval::failEvalParse / php-src zif_eval (#27107).
     *
     * Seed Error→CompileError→ParseError before allocate so catch(Throwable) / get_class work
     * under thin AOT (external-only class without parents aborted previously).
     */
    private static function emitEvalParseError(
        JIT $jit,
        string $literal,
        string $evalFile,
        ?string $detail
    ): void {
        $message = VmEval::normalizeParseMessage(
            $detail ?? 'Parse error',
            $literal
        );
        $object = $jit->context->type->object;
        // Parent chain before ParseError allocate (#27107).
        $object->lookup('Error');
        $object->lookup('CompileError');
        $object->lookup('ParseError');
        TryCatchHelper::emitCatchableClassError(
            $jit->context,
            'ParseError',
            $message,
            $jit,
            $evalFile,
            1
        );
        BasicBlockHelper::ensureOpenInsertBlock($jit->context, 'eval_parse_error_cont');
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
