<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPLLVM\Value\Function_;
use PHPCompiler\Block;
use PHPCompiler\Compiler\CompileFatal;
use PHPCompiler\CompilerVersion;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\Type\Object_;
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

        // Isolated probe cannot see outer-unit parents — cross-eval final property
        // overrides would otherwise emitFalse and print redef_ok (#28437 / #22988).
        try {
            self::rejectOuterUnitFinalPropertyOverride($jit, $evalBlock, $evalFile);
        } catch (CompileFatal $e) {
            if (\defined('STDERR') && \is_resource(STDERR)) {
                fwrite(STDERR, $e->zendStderrLine());
            }
            throw $e;
        }

        // Decl succeeded but cannot MCJIT-inline into the outer unit (#25535).
        self::emitFalse($jit, $resultOp);
    }

    /**
     * php-src zend_inheritance.c — "Cannot override final property %s::$%s".
     *
     * AOT lowers decl eval() to emitFalse (MCJIT cannot inline TYPE_DECLARE_CLASS).
     * The isolated probe Runtime has no outer parent ClassEntry, so FinalPropertyOverrideCheck
     * and inheritFromParent never run — reject against the outer Object_ tables here (#28437).
     */
    private static function rejectOuterUnitFinalPropertyOverride(
        JIT $jit,
        Block $evalBlock,
        string $evalFile
    ): void {
        if (!CompilerVersion::supportsFinalProperties()) {
            return;
        }
        $object = $jit->context->type->object;
        $seen = new \SplObjectStorage();
        $stack = [$evalBlock];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if (!$block instanceof Block || $seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_CLASS === $op->type && $op->block1 instanceof Block) {
                    $parentName = self::operandString($block, $op->arg2);
                    if (null !== $parentName && '' !== $parentName) {
                        self::rejectClassBodyAgainstParentFinals(
                            $object,
                            $op->block1,
                            $parentName,
                            $evalFile
                        );
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
        }
    }

    /**
     * Walk eval'd class body property decls against an outer-unit parent chain.
     */
    private static function rejectClassBodyAgainstParentFinals(
        Object_ $object,
        Block $classBody,
        string $parentName,
        string $evalFile
    ): void {
        $parentLc = strtolower(ltrim($parentName, '\\'));
        $seen = new \SplObjectStorage();
        $stack = [$classBody];
        while ([] !== $stack) {
            $block = array_pop($stack);
            if ($seen->contains($block)) {
                continue;
            }
            $seen->attach($block);
            foreach ($block->opCodes as $op) {
                if (OpCode::TYPE_DECLARE_PROPERTY === $op->type
                    || OpCode::TYPE_DECLARE_STATIC_PROPERTY === $op->type
                ) {
                    $propName = self::operandString($block, $op->arg1);
                    if (null === $propName || '' === $propName) {
                        continue;
                    }
                    $owner = self::findFinalPropertyOwnerDisplay($object, $parentLc, $propName);
                    if (null !== $owner) {
                        // Outer AOT/JIT emit prints zendStderrLine once (#25457); do not fwrite here.
                        throw new CompileFatal(
                            $evalFile,
                            1,
                            sprintf('Cannot override final property %s::$%s', $owner, $propName)
                        );
                    }
                }
                foreach ([$op->block1, $op->block2, $op->block3] as $sub) {
                    if ($sub instanceof Block) {
                        $stack[] = $sub;
                    }
                }
            }
        }
    }

    /**
     * @return non-empty-string|null display name of the declaring final owner
     */
    private static function findFinalPropertyOwnerDisplay(
        Object_ $object,
        string $startParentLc,
        string $propName
    ): ?string {
        $current = $startParentLc;
        $visited = [];
        $guard = 0;
        while (null !== $current && '' !== $current && !isset($visited[$current])) {
            if (++$guard > 256) {
                break;
            }
            $visited[$current] = true;
            $classId = $object->classIdForLowerName($current);
            if (null === $classId) {
                break;
            }
            // Private parent slots coexist with same-name child props (zend_inheritance.c).
            $vis = $object->propertyVisibility($classId, $propName);
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                $current = $object->parentClassLc($current);

                continue;
            }
            if ($object->isPropertyFinal($classId, $propName)) {
                $display = $object->classNameForId($classId);

                return '' !== $display ? $display : $current;
            }
            $current = $object->parentClassLc($current);
        }

        return null;
    }

    private static function operandString(Block $block, ?int $operandIdx): ?string
    {
        if (null === $operandIdx) {
            return null;
        }
        $operand = $block->getOperand($operandIdx);
        if ($operand instanceof Literal && is_string($operand->value)) {
            return $operand->value;
        }
        if (isset($block->constants[$operandIdx])) {
            return $block->constants[$operandIdx]->toString();
        }

        return null;
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
