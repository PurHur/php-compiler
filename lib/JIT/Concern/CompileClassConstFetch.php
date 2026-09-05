<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;

/**
 * CLASS_CONST_FETCH opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_CLASS_CONST_FETCH}.
 * Wrapped in {@code switch (true)} so original case-level {@code break}
 * semantics are preserved (move-only; no IR shape change).
 *
 * php-src: Zend/zend_vm_def.h (ZEND_FETCH_CLASS_CONSTANT),
 * Zend/zend_execute.c (zend_fetch_class_constant / LSB static::) —
 * move-only Concern extract; no new C ABI.
 */
trait CompileClassConstFetch
{
    private function compileClassConstFetchOp(
        Block $block,
        OpCode $op
    ): void {
        switch (true) {
            case true:
                    $classOp = $block->getOperand($op->arg2);
                    $nameOp = $block->getOperand($op->arg3);
                    if ($nameOp instanceof Operand\Literal && 'class' === strtolower($nameOp->value)) {
                        // Literal `static::class` must resolve at runtime (LSB) under AOT —
                        // same rule as `static::CONST` below (#20251, #19614, zend_execute.c).
                        $classIsStaticKeyword = $classOp instanceof Operand\Literal
                            && \is_string($classOp->value)
                            && 'static' === strtolower($classOp->value);
                        if (
                            $classIsStaticKeyword
                            && \PHPCompiler\JIT\LateStaticBindingHelper::useRuntimeLateStatic($this->context)
                        ) {
                            $classIdVal = \PHPCompiler\JIT\ClassConstFetchHelper::emitStaticKeywordClassIdForPseudoConst(
                                $this->context->type->object,
                                $block
                            );
                            $classNameVal = \PHPCompiler\JIT\ClassConstFetchHelper::emitClassNameStringFromClassId(
                                $this->context->type->object,
                                $classIdVal
                            );
                            $this->assignOperandValue($block->getOperand($op->arg1), $classNameVal);
                            break;
                        }
                        if ($classOp instanceof Operand\Literal) {
                            // self/parent::class — resolve display name then constantStringFromString
                            // (fromLiteral bake of trait name "T" fails LLVM verify in nested closures, #26459).
                            $lcClass = strtolower((string) $classOp->value);
                            if (\in_array($lcClass, ['self', 'parent'], true)) {
                                $display = $this->resolveClassNameForPseudoConst($block, $classOp);
                                if ('parent' === $lcClass) {
                                    // resolveClassNameForPseudoConst already returns parent FQCN
                                }
                                $classNameVal = $this->context->builder->load(
                                    $this->context->constantStringFromString($display)
                                );
                                $this->assignOperandValue($block->getOperand($op->arg1), $classNameVal);
                                break;
                            }
                            $className = $this->resolveClassNameForPseudoConst($block, $classOp);
                            $lit = new Operand\Literal($className);
                            $lit->type = Type::string();
                            $this->assignOperand(
                                $block->getOperand($op->arg1),
                                \PHPCompiler\JIT\Variable::fromLiteral($this->context, $lit)
                            );
                            break;
                        }
                        $classVar = $this->context->getVariableFromOp($classOp);
                        if ($op->classConstFetchOnObject) {
                            $classNameVal = \PHPCompiler\JIT\ClassConstFetchHelper::emitExprClassPseudoConst(
                                $this->context->type->object,
                                $classVar
                            );
                        } elseif (\PHPCompiler\JIT\Variable::TYPE_OBJECT === $classVar->type) {
                            $classNameVal = \PHPCompiler\JIT\ReflectionBuiltinHelper::getClassName($this->context, $classVar);
                        } else {
                            $classNameVal = \PHPCompiler\JIT\ClassConstFetchHelper::emitClassPseudoConstStringValue(
                                $this->context->type->object,
                                $block,
                                $classVar
                            );
                        }
                        $this->assignOperandValue($block->getOperand($op->arg1), $classNameVal);
                        break;
                    }
                    // Literal `static::CONST` must resolve at runtime (LSB); baking
                    // declaring-class id here matches self:: (#19614, zend_execute.c).
                    $classIsStaticKeyword = $classOp instanceof Operand\Literal
                        && \is_string($classOp->value)
                        && 'static' === strtolower($classOp->value);
                    if ($classOp instanceof Operand\Literal && !$classIsStaticKeyword) {
                        $classId = $this->context->type->object->resolveClassId($classOp);
                        if ($nameOp instanceof Operand\Literal) {
                            if ('native_type_map' === strtolower($nameOp->value) || 'type_map' === strtolower($nameOp->value)) {
                                $classLabel = strtolower($classOp->value);
                                if (str_contains($classLabel, 'variable')) {
                                    $mapVar = $this->jitVariableArrayClassConstant($nameOp->value);
                                    if (null !== $mapVar) {
                                        $this->assignOperand($block->getOperand($op->arg1), $mapVar);
                                        break;
                                    }
                                }
                            }
                            $opcodeConst = $this->jitFoldOpCodeClassConstant($classOp, $nameOp->value);
                            if (null !== $opcodeConst) {
                                $this->assignOperand($block->getOperand($op->arg1), $opcodeConst);
                                break;
                            }
                            \PHPCompiler\JIT\ClassConstVisibilityJitGuard::emitBeforeFetch(
                                $this->context->type->object,
                                $this,
                                $block,
                                $classId,
                                $nameOp->value
                            );
                            if ($this->context->type->object->isEnumClassId($classId)) {
                                \PHPCompiler\JIT\BackedEnumDuplicateJitGuard::emitBeforeEnumCaseFetch(
                                    $this->context->type->object,
                                    $this,
                                    $block,
                                    $classId
                                );
                            }
                            try {
                                $value = $this->context->type->object->classConstFetch(
                                    $classId,
                                    $nameOp->value,
                                    $block,
                                    $classOp instanceof Operand\Literal && \is_string($classOp->value) ? $classOp->value : null
                                );
                            } catch (\LogicException $e) {
                                // Runtime Error for missing / non-inherited private parent const (#19615).
                                if (!str_starts_with($e->getMessage(), 'Undefined constant ')) {
                                    throw $e;
                                }
                                $message = $e->getMessage();
                                \PHPCompiler\JIT\Builtin\ErrorRaise::registerDeclarations($this->context);
                                \PHPCompiler\JIT\Builtin\ErrorRaise::ensureLinked($this->context);
                                $resultOp = $block->getOperand($op->arg1);
                                $nullLit = new Operand\Literal(null);
                                $nullLit->type = Type::null();
                                $this->assignOperand($resultOp, \PHPCompiler\JIT\Variable::fromLiteral($this->context, $nullLit));
                                if ([] !== $this->context->tryCatch->handlerStack) {
                                    \PHPCompiler\JIT\TryCatchHelper::emitCatchableErrorMessage($this->context, $this, $message);
                                } else {
                                    \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise($this->context, $message);
                                }
                                break;
                            }
                            $resultOp = $block->getOperand($op->arg1);
                            if ($this->context->type->object->isEnumClassId($classId)
                                && $classOp instanceof Operand\Literal) {
                                $resultOp->type = Type::object($classOp->value);
                            }
                            $this->assignOperand($resultOp, $value);
                            break;
                        }
                        $nameVar = $this->context->getVariableFromOp($nameOp);
                        $value = $this->context->type->object->classConstFetchDynamic(
                            $classId,
                            $nameVar,
                            $classOp,
                            $block,
                            $this
                        );
                        $this->assignOperand($block->getOperand($op->arg1), $value);
                        break;
                    }
                    $classVar = $this->context->getVariableFromOp($classOp);
                    if ($nameOp instanceof Operand\Literal) {
                        if ('native_type_map' === strtolower($nameOp->value) || 'type_map' === strtolower($nameOp->value)) {
                            break;
                        }
                        $opcodeConst = $this->jitFoldOpCodeClassConstant($classOp, $nameOp->value);
                        if (null !== $opcodeConst) {
                            $this->assignOperand($block->getOperand($op->arg1), $opcodeConst);
                            break;
                        }
                        $value = \PHPCompiler\JIT\ClassConstFetchHelper::fetchLiteralConstWithRuntimeClass(
                            $this->context->type->object,
                            $block,
                            $classVar,
                            $classOp,
                            $nameOp->value,
                            $this
                        );
                        $this->assignOperand($block->getOperand($op->arg1), $value);
                        break;
                    }
                    $nameVar = $this->context->getVariableFromOp($nameOp);
                    $value = \PHPCompiler\JIT\ClassConstFetchHelper::fetchDynamicWithRuntimeClass(
                        $this->context->type->object,
                        $block,
                        $classVar,
                        $nameVar,
                        $classOp
                    );
                    $this->assignOperand($block->getOperand($op->arg1), $value);
                    break;

        }
    }
}
