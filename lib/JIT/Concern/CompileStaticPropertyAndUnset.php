<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Static property fetch/unset and unset opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_STATIC_PROPERTY_FETCH},
 * {@code TYPE_STATIC_PROPERTY_UNSET}, and {@code TYPE_UNSET} so the monolith switch
 * shrinks toward split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c (ZEND_FETCH_STATIC_PROP_*, ZEND_UNSET_*),
 * Zend/zend_object_handlers.c (unset_property) — move-only Concern extract; no new
 * C ABI and no opcode/IR shape change.
 */
trait CompileStaticPropertyAndUnset
{
    private function compileStaticPropertyOrUnsetOp(
        Block $block,
        OpCode $op,
        int $i,
        PHPLLVM\Value $func,
        PHPLLVM\BasicBlock $basicBlock
    ): void {
        switch ($op->type) {
            case OpCode::TYPE_STATIC_PROPERTY_FETCH:
                $classOp = $block->getOperand($op->arg2);
                $nameOp = $block->getOperand($op->arg3);
                $useRuntimeStatic = $classOp instanceof Operand\Literal
                    && 'static' === strtolower(ltrim((string) $classOp->value, '\\'))
                    && \PHPCompiler\JIT\LateStaticBindingHelper::useRuntimeLateStatic($this->context);
                if ($useRuntimeStatic && $nameOp instanceof Operand\Literal) {
                    // AOT: `static::$prop` must use get_called_scope(), not declaring class
                    // (#34912; peer static::CONST / static::method #19614 / #24169).
                    $destSlot = (int) $op->arg1;
                    $forWrite = $this->varFetchDestUsedAsAssignLvalue($block, $i, $destSlot)
                        || $this->varFetchDestUsedAsIncDec($block, $i, $destSlot)
                        || $this->varFetchDestUsedAsCompoundAssign($block, $i, $destSlot)
                        || $this->varFetchDestUsedAsDimWriteContainer($block, $i, $destSlot)
                        || $this->varFetchDestUsedAsDimRwContainer($block, $i, $destSlot)
                        || $this->varFetchDestUsedAsByRefReturn($block, $i, $destSlot);
                    $runtimeClassId = \PHPCompiler\JIT\ClassConstFetchHelper::emitStaticKeywordClassIdForPseudoConst(
                        $this->context->type->object,
                        $block
                    );
                    $fetched = $this->context->type->object->staticPropertyFetchByRuntimeClassId(
                        $runtimeClassId,
                        $nameOp->value,
                        $forWrite
                    );
                    if ($forWrite) {
                        $fetched->objectPropertyName = $nameOp->value;
                    }
                    $this->context->setVariableOp($block->getOperand($op->arg1), $fetched);
                    break;
                }
                $classId = $this->context->type->object->resolveClassId($classOp);
                $className = $this->context->type->object->classNameForId($classId);
                if ($nameOp instanceof Operand\Literal) {
                    $destSlot = (int) $op->arg1;
                    // FETCH_STATIC_PROP_W / RW: assign / ++/-- / += / dim-write must alias
                    // the module slot (zend_execute.c ZEND_FETCH_STATIC_PROP_RW). FETCH_R
                    // by-value copies (zend_array_dup) so `$b = A::$a` does not share
                    // storage (#32307). Untyped `self::$x++` was FETCH_R and lost the store
                    // (#32313, #31968 group 3). By-ref return (`function &f(){ return C::$x; }`)
                    // is ZEND_FETCH_STATIC_PROP_W too — FETCH_R copies drop staticPropertyGlobal
                    // and the caller’s reference dangles (#34727 / re-#34717).
                    $forWrite = $this->varFetchDestUsedAsAssignLvalue($block, $i, $destSlot)
                        || $this->varFetchDestUsedAsIncDec($block, $i, $destSlot)
                        || $this->varFetchDestUsedAsCompoundAssign($block, $i, $destSlot)
                        || $this->varFetchDestUsedAsDimWriteContainer($block, $i, $destSlot)
                        || $this->varFetchDestUsedAsDimRwContainer($block, $i, $destSlot)
                        || $this->varFetchDestUsedAsByRefReturn($block, $i, $destSlot);
                    if (!$forWrite) {
                        $hookFetched = \PHPCompiler\JIT\PropertyHookDispatch::tryEmitStaticPropertyGet(
                            $this->context,
                            $className,
                            $nameOp->value,
                            $block
                        );
                        if (null !== $hookFetched) {
                            $this->assignOperandValue($block->getOperand($op->arg1), $hookFetched);
                            break;
                        }
                    }
                    \PHPCompiler\JIT\StaticPropertyVisibilityJitGuard::emitBeforeFetch(
                        $this->context->type->object,
                        $this,
                        $block,
                        $classId,
                        $nameOp->value
                    );
                    $fetched = $this->context->type->object->staticPropertyFetch(
                        $classId,
                        $nameOp->value,
                        $forWrite
                    );
                    if ($forWrite) {
                        $fetched->staticPropertyHookClassLc = strtolower(ltrim($className, '\\'));
                        $fetched->objectPropertyName = $nameOp->value;
                    }
                } else {
                    $nameVar = $this->context->getVariableFromOp($nameOp);
                    $fetched = $this->context->type->object->staticPropertyFetchDynamic($classId, $nameVar);
                }
                $this->context->setVariableOp($block->getOperand($op->arg1), $fetched);
                break;
            case OpCode::TYPE_STATIC_PROPERTY_UNSET:
                $classOp = $block->getOperand($op->arg2);
                $nameOp = $block->getOperand($op->arg3);
                $classId = $this->context->type->object->resolveClassId($classOp);
                if ($nameOp instanceof Operand\Literal) {
                    $this->context->type->object->staticPropertyUnset($classId, $nameOp->value, $this);
                } else {
                    $nameVar = $this->context->getVariableFromOp($nameOp);
                    $this->context->type->object->staticPropertyUnsetDynamic($classId, $nameVar, $this);
                }
                break;
            case OpCode::TYPE_UNSET:
                if (null === $op->arg3) {
                    $targetOp = null !== $op->arg2
                        ? ($block->operandForScopeSlot($op->arg2) ?? $block->getOperand($op->arg2))
                        : null;
                    if (null === $targetOp) {
                        break;
                    }
                    $this->context->aliasVariableOpFromSlot($block, $targetOp);
                    $unsetName = \PHPCompiler\JIT\OperandName::resolve($targetOp);
                    if (null !== $unsetName && '' !== $unsetName) {
                        $resolvedUnset = $this->context->resolveRefAliasName($unsetName);
                        $foreachByRefUnset = $this->context->isForeachByRefLocalName(
                            $resolvedUnset,
                            $block
                        );
                        if (isset($this->context->namedVariableBindings[$resolvedUnset])) {
                            $bound = $this->context->namedVariableBindings[$resolvedUnset];
                            // foreach ($a as &$v); unset($v) breaks the reference — it must not
                            // writeNull through the loop-body HT entry PHI (#24010 domination).
                            if (
                                $bound->borrowedValueEntry
                                || null !== $bound->foreachByRefPackedArm
                                || isset($this->context->foreachByRefLocalNames[$resolvedUnset])
                                || $foreachByRefUnset
                            ) {
                                $nullVar = $this->jitNullVariable();
                                $this->context->bindVariableByName($resolvedUnset, $nullVar);
                                $this->context->setVariableOp($targetOp, $nullVar);
                                unset(
                                    $this->context->foreachByRefLocalNames[$resolvedUnset],
                                    $this->context->namedVariableBindings[$resolvedUnset]
                                );
                                $this->context->bindVariableByName($resolvedUnset, $nullVar);
                                break;
                            }
                            // {main} / $GLOBALS symbols are KIND_VALUE functionStaticGlobal
                            // boxes (__value__**), not stack KIND_VARIABLE allocas (#27118).
                            if (
                                Variable::TYPE_VALUE === $bound->type
                                && (
                                    Variable::KIND_VARIABLE === $bound->kind
                                    || $bound->functionStaticGlobal
                                    || Variable::KIND_VALUE === $bound->kind
                                )
                            ) {
                                $this->jitWriteNullForUnset(
                                    \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $bound)
                                );
                                // Keep canonical CV; jitNullVariable() split left loop back-edge
                                // assigns delref'ing GC orphans (#36245 loop_unset).
                                $bound->isNullConstant = true;
                                $this->context->bindVariableByName($resolvedUnset, $bound);
                                $this->context->setVariableOp($targetOp, $bound);
                                $this->jitClearAssignResultObjectMirrorForNamedUnset($block, $op->arg2);
                                break;
                            }
                            if (
                                Variable::KIND_VARIABLE === $bound->kind
                                && Variable::TYPE_OBJECT === $bound->type
                            ) {
                                // Native __object__* locals: delref then null the slot (#26795 / #4096 AOT unset).
                                $obj = $this->context->builder->load($bound->value);
                                $this->context->refcount->delref($obj);
                                $this->context->builder->store(
                                    $this->context->getTypeFromString('__object__*')->constNull(),
                                    $bound->value
                                );
                                break;
                            }
                            if (
                                Variable::KIND_VARIABLE === $bound->kind
                                && Variable::TYPE_HASHTABLE === $bound->type
                            ) {
                                // Native __hashtable__* locals: same as objects — named-binding
                                // unset must not fall through without delref (#36388). Without
                                // this, inferred int[]/$a keeps the HT and every loop iteration
                                // leaks (~288 B) under thin AOT.
                                // php-src: Zend/zend_execute.c ZEND_UNSET_VAR → zval_ptr_dtor.
                                $ht = $this->context->builder->load($bound->value);
                                $this->context->refcount->delref(
                                    $this->context->builder->pointerCast(
                                        $ht,
                                        $this->context->getTypeFromString('__ref__virtual*')
                                    )
                                );
                                $this->context->builder->store(
                                    $this->context->getTypeFromString('__hashtable__*')->constNull(),
                                    $bound->value
                                );
                                break;
                            }
                            if (
                                Variable::KIND_VARIABLE === $bound->kind
                                && Variable::TYPE_STRING === $bound->type
                            ) {
                                $str = $this->context->builder->load($bound->value);
                                $this->context->refcount->delref(
                                    $this->context->builder->pointerCast(
                                        $str,
                                        $this->context->getTypeFromString('__ref__virtual*')
                                    )
                                );
                                $this->context->builder->store(
                                    $this->context->getTypeFromString('__string__*')->constNull(),
                                    $bound->value
                                );
                                break;
                            }
                        } elseif ($foreachByRefUnset) {
                            // Block order may compile unset before the foreach body; CFG scan
                            // still knows $v is a foreach-by-ref dest (#24010 / i11 differential).
                            $nullVar = $this->jitNullVariable();
                            $this->context->bindVariableByName($resolvedUnset, $nullVar);
                            $this->context->setVariableOp($targetOp, $nullVar);
                            unset($this->context->foreachByRefLocalNames[$resolvedUnset]);
                            break;
                        }
                    }
                    if (
                        !$this->context->hasVariableOp($targetOp)
                        && null === \PHPCompiler\JIT\OperandName::resolve($targetOp)
                    ) {
                        break;
                    }
                    if ($this->context->hasVariableOp($targetOp)) {
                        $target = $this->context->getVariableFromOp($targetOp);
                        if (
                            $target->borrowedValueEntry
                            || null !== $target->foreachByRefPackedArm
                            || (
                                null !== $unsetName
                                && '' !== $unsetName
                                && $this->context->isForeachByRefLocalName(
                                    $this->context->resolveRefAliasName($unsetName),
                                    $block
                                )
                            )
                        ) {
                            $nullVar = $this->jitNullVariable();
                            $this->context->setVariableOp($targetOp, $nullVar);
                            if (null !== $unsetName && '' !== $unsetName) {
                                $resolvedUnset = $this->context->resolveRefAliasName($unsetName);
                                $this->context->bindVariableByName($resolvedUnset, $nullVar);
                                unset($this->context->foreachByRefLocalNames[$resolvedUnset]);
                            }
                            break;
                        }
                        if (
                            null !== $target->writableHt
                            && null !== $target->writableStringKey
                            && \PHPCompiler\JIT\Builtin::LOAD_TYPE_STANDALONE === $this->context->loadType
                        ) {
                            \PHPCompiler\JIT\HashTableHelper::unsetStringKey(
                                $this->context,
                                $target->writableHt,
                                $target->writableStringKey
                            );
                            break;
                        }
                        if (
                            Variable::TYPE_VALUE === $target->type
                            && (
                                Variable::KIND_VARIABLE === $target->kind
                                || $target->functionStaticGlobal
                                || Variable::KIND_VALUE === $target->kind
                            )
                        ) {
                            $this->jitWriteNullForUnset(
                                \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $target)
                            );
                            $target->isNullConstant = true;
                            if (null !== $unsetName && '' !== $unsetName) {
                                $this->jitClearAssignResultObjectMirrorForNamedUnset($block, $op->arg2);
                            }
                            break;
                        }
                        $target->free();
                        $this->context->setVariableOp($targetOp, $this->jitNullVariable());
                    }
                } else {
                    \PHPCompiler\JIT\UnsetHelper::compileOffset($this->context, $block, $op, $this);
                }
                break;
        }
    }
}
