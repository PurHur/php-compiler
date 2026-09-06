<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Object-property `.=` / `**=` and concat-chain flattening for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileIncDecAndConcatFlatten}: {@code compileObjectPropertyConcatOp}
 * through {@code tryCompileConcatChainFlatten} (property mutate, ephemeral concat operands,
 * main-script publish, chain flatten). Move-only; no IR shape change.
 *
 * php-src: Zend/zend_operators.c (ZEND_ASSIGN_CONCAT / zend_string_extend),
 * Zend/zend_object_handlers.c (zend_std_write_property) — move-only Concern extract; no new C ABI.
 */
trait CompileObjectPropertyConcatPowAndFlatten
{
    /** .= on object properties: concat into new string, guard readonly, store via slot (#3149). */
    private function compileObjectPropertyConcatOp(Variable $dest, Variable $left, Variable $right): void
    {
        if (null === $dest->objectPropertySlot || null === $dest->objectPropertyType) {
            throw new \LogicException('objectPropertySlot requires objectPropertyType');
        }
        $newVal = $this->compileConcatIntoNewString($left, $right);
        JIT\DynamicObjectReadonlyGuard::emitBeforePropertyStore(
            $this->context,
            $dest,
            $this->context->jitEnclosingBlock
        );
        JIT\ReadonlyClassGuard::emitBeforePropertyStore(
            $this->context,
            $dest,
            $this->context->jitEnclosingBlock,
            'modify',
            $this
        );
        if (JIT\AsymmetricVisibilityGuard::emitBeforePropertyStore(
            $this->context,
            $this,
            $dest,
            $this->context->jitEnclosingBlock
        )) {
            return;
        }
        if (null !== $dest->objectPropertyDnfArms) {
            JIT\DnfParamCheck::enforcePropertyWrite(
                $this->context,
                $newVal,
                $dest->objectPropertyDnfArms
            );
        } elseif (
            null !== $dest->objectPropertyClassConstraint
            && '' !== $dest->objectPropertyClassConstraint
        ) {
            JIT\TypedPropertyClassAssignCheck::enforce(
                $this->context,
                $newVal,
                $dest->objectPropertyClassConstraint,
                $dest->objectPropertyClassName ?? '',
                $dest->objectPropertyName ?? 'property',
                $dest->objectPropertyDeclaredTypeLabel ?? $dest->objectPropertyClassConstraint,
                $dest->objectPropertyAllowsNull
            );
        }
        JIT\ReadonlyClassGuard::emitStoreUnlessPending(
            $this->context,
            function () use ($dest, $newVal): void {
                $this->context->type->object->propertyStore(
                    $dest->objectPropertySlot,
                    $newVal,
                    $dest->objectPropertyType
                );
            }
        );
    }

    /** `$obj->prop **= n` / by-ref `$r **= n` in-place — bypass assignOperand slot strip (#35978). */
    private function compileObjectPropertyPowOp(Variable $dest, Variable $left, Variable $right): void
    {
        if (null === $dest->objectPropertySlot || null === $dest->objectPropertyType) {
            throw new \LogicException('objectPropertySlot requires objectPropertyType');
        }
        $pow = new \PHPCompiler\ext\standard\pow();
        $this->context->powReturnValueBox = true;
        $powResult = $pow->call($this->context, $left, $right);
        $this->context->powReturnValueBox = false;
        $newVal = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            $powResult
        );
        JIT\DynamicObjectReadonlyGuard::emitBeforePropertyStore(
            $this->context,
            $dest,
            $this->context->jitEnclosingBlock
        );
        JIT\ReadonlyClassGuard::emitBeforePropertyStore(
            $this->context,
            $dest,
            $this->context->jitEnclosingBlock,
            'modify',
            $this
        );
        if (JIT\AsymmetricVisibilityGuard::emitBeforePropertyStore(
            $this->context,
            $this,
            $dest,
            $this->context->jitEnclosingBlock
        )) {
            return;
        }
        if (null !== $dest->objectPropertyDnfArms) {
            JIT\DnfParamCheck::enforcePropertyWrite(
                $this->context,
                $newVal,
                $dest->objectPropertyDnfArms
            );
        } elseif (
            null !== $dest->objectPropertyClassConstraint
            && '' !== $dest->objectPropertyClassConstraint
        ) {
            JIT\TypedPropertyClassAssignCheck::enforce(
                $this->context,
                $newVal,
                $dest->objectPropertyClassConstraint,
                $dest->objectPropertyClassName ?? '',
                $dest->objectPropertyName ?? 'property',
                $dest->objectPropertyDeclaredTypeLabel ?? $dest->objectPropertyClassConstraint,
                $dest->objectPropertyAllowsNull
            );
        }
        JIT\ReadonlyClassGuard::emitStoreUnlessPending(
            $this->context,
            function () use ($dest, $newVal): void {
                $this->context->type->object->propertyStore(
                    $dest->objectPropertySlot,
                    $newVal,
                    $dest->objectPropertyType
                );
            }
        );
    }

    /**
     * Entry-alloca ephemeral concat when the left operand is a named {@see Operand\Variable} (#23798).
     *
     * ConcatList chain continuations also use entry alloca (via {@see Variable::$ephemeralConcatTemp}
     * in the CONCAT handler) — KIND_VALUE-only links stack-color with dead fopen() value boxes and
     * heap-corrupt under AOT (#24024). Named Temporaries still use assignOperand on the first dead
     * link so `$s . '1'` consecutive echoes stay correct.
     */
    private function concatDeadOperandNeedsEntryAlloca(Block $block, Operand $destOp, ?Operand $leftOp): bool
    {
        if (!$leftOp instanceof Operand\Variable) {
            return false;
        }
        if ($leftOp === $destOp) {
            return false;
        }
        $name = JIT\OperandName::resolve($leftOp);
        if (null === $name || '' === $name) {
            return false;
        }
        if (\PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
            return false;
        }

        return $block->hasLocallyWrittenVariableName($name);
    }

    /**
     * Store concat into a php-cfg dead Temporary (echo/call arg) with entry alloca lifetime (#23798).
     *
     * assignOperand → makeVariableFromValueOp left a bare {@see KIND_VALUE} __string__*; a second
     * concat from the same local then double-freed or corrupted the heap under AOT.
     *
     * At {main}, also publish into the script-global heap box for every CV name on the dest
     * slot — echo / ARG_SEND / var_export resolve named locals via ensureScriptGlobal and
     * would otherwise read an empty box while the ephemeral alloca held the real string
     * (#36366 p16: `$out = implode(...) . "\n"` printed checksum=0:0).
     */
    private function assignEphemeralConcatOperand(
        Block $block,
        Operand $destOp,
        Variable $left,
        Variable $right,
        \PHPLLVM\Value\Function_ $func,
        ?\PHPCfg\Operand $leftOp = null,
        ?\PHPCfg\Operand $rightOp = null
    ): void {
        $newVal = $this->compileConcatIntoNewString($left, $right, $leftOp, $rightOp);
        $destSlot = JIT\BasicBlockHelper::entryAllocaForFunction(
            $this->context,
            $func,
            $this->context->getTypeFromString('__string__*')
        );
        $promoted = new Variable(
            $this->context,
            Variable::TYPE_STRING,
            Variable::KIND_VARIABLE,
            $destSlot
        );
        $promoted->ephemeralConcatTemp = true;
        JIT\BasicBlockHelper::storeAtFunctionEntry(
            $this->context,
            $func,
            $this->context->type->string->pointer->constNull(),
            $destSlot
        );
        $this->context->builder->store($newVal->value, $destSlot);
        $this->context->setVariableOp($destOp, $promoted);
        $this->bindPromotedStringConcatDest($block, $destOp, $promoted);
        if (null !== ($newVal->compileTimeString ?? null)) {
            $promoted->compileTimeString = $newVal->compileTimeString;
        }
        $this->publishMainScriptNamedConcatResult($block, $destOp, $newVal);
    }

    /**
     * Seed a promoted `__string__**` from a boxed CV when the slot is still null.
     *
     * Emitted into the CONCAT block so the first `$buf .= …` after `$buf = '…'` copies the
     * box payload (via {@see __string__separate}) before {@see String_::appendInPlace}.
     * Later iterations keep the grown native pointer (#36386 / #36410).
     */
    private function seedNativeStringSlotFromValueBox(Variable $valueBox, PHPLLVM\Value $destSlot): void
    {
        $strPtrTy = $this->context->getTypeFromString('__string__*');
        $cur = $this->context->builder->load($destSlot);
        $tag = 'seedStrFromBox'.(string) spl_object_id($valueBox);
        $nullBlock = JIT\BasicBlockHelper::append($this->context, 'seed_null_'.$tag);
        $readyBlock = JIT\BasicBlockHelper::append($this->context, 'seed_ready_'.$tag);
        $isNull = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $cur,
            $strPtrTy->constNull()
        );
        $this->context->builder->branchIf(
            $this->context->castToBool($isNull),
            $nullBlock,
            $readyBlock
        );

        $this->context->builder->positionAtEnd($nullBlock);
        $fromBox = JIT\JitNativeString::coerce($this->context, $valueBox);
        $owned = $this->context->builder->call(
            $this->context->lookupFunction('__string__separate'),
            $this->context->helper->loadValue($fromBox)
        );
        $this->context->builder->store($owned, $destSlot);
        $this->context->builder->branch($readyBlock);

        $this->context->builder->positionAtEnd($readyBlock);
    }

    /**
     * If $valueBox holds the same __string__* as $destSlot, clear the box (delref) so
     * appendInPlace/realloc can move the buffer with a unique owner (#36386).
     */
    private function dropValueBoxStringAliasIfSame(Variable $valueBox, PHPLLVM\Value $destSlot): void
    {
        if (
            Variable::TYPE_VALUE !== $valueBox->type
            && !JIT\JitValueBox::isValueOperand($valueBox)
        ) {
            return;
        }
        $strPtrTy = $this->context->getTypeFromString('__string__*');
        $held = JIT\JitValueBox::readStringOrNull($this->context, $valueBox);
        $cur = $this->context->builder->load($destSlot);
        $tag = 'dropBoxAlias'.(string) spl_object_id($valueBox).(string) spl_object_id($destSlot);
        $dropBlock = JIT\BasicBlockHelper::append($this->context, 'drop_alias_'.$tag);
        $contBlock = JIT\BasicBlockHelper::append($this->context, 'drop_alias_cont_'.$tag);
        $same = $this->context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $held, $cur);
        $nonNull = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $cur,
            $strPtrTy->constNull()
        );
        $shouldDrop = $this->context->builder->bitwiseAnd(
            $this->context->castToBool($same),
            $this->context->castToBool($nonNull)
        );
        $this->context->builder->branchIf($shouldDrop, $dropBlock, $contBlock);
        $this->context->builder->positionAtEnd($dropBlock);
        // addref before writeNull so a false-share at rc=1 (box+destSlot without a
        // matching addref on publish) is not freed out from under destSlot (#36386).
        $this->context->refcount->addref($cur);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            JIT\JitValueBox::valuePtrFromVariable($this->context, $valueBox)
        );
        $this->context->builder->branch($contBlock);
        $this->context->builder->positionAtEnd($contBlock);
    }

    /**
     * Same as {@see dropValueBoxStringAliasIfSame} for {main} script-globals that
     * publishMainScriptNamedConcatResult keeps in sync with the promoted slot.
     */
    private function dropMainScriptStringAliasIfSame(
        Block $block,
        Operand $destOp,
        PHPLLVM\Value $destSlot
    ): void {
        if (!$block->isMainScript()) {
            return;
        }
        $names = [];
        $destName = JIT\OperandName::resolve($destOp);
        if (null !== $destName && '' !== $destName) {
            $names[$destName] = true;
        }
        $slot = $block->slotForOperand($destOp);
        if (null !== $slot) {
            foreach ($block->scopedOperands() as $scopeOp) {
                if ($block->slotForOperand($scopeOp) !== $slot) {
                    continue;
                }
                $scopeName = JIT\OperandName::resolve($scopeOp);
                if (null !== $scopeName && '' !== $scopeName) {
                    $names[$scopeName] = true;
                }
            }
        }
        foreach ($names as $name => $_) {
            $name = (string) $name;
            if (\PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if ($this->shouldDeferScriptGlobalForInlineIncludeBinding($name, $destOp, $block)) {
                continue;
            }
            $sg = $this->context->ensureScriptGlobal($name);
            $this->dropValueBoxStringAliasIfSame($sg, $destSlot);
        }
    }

    /**
     * {main} CV reads go through ensureScriptGlobal() boxes (#23842). Ephemeral /
     * promoted CONCAT stores only an __string__* alloca — without this write, echo and
     * ARG_SEND of `$b = $a . "x"` at top level see NULL / empty (#36366).
     */
    private function publishMainScriptNamedConcatResult(
        Block $block,
        Operand $destOp,
        Variable $stringVal
    ): void {
        if (!$block->isMainScript()) {
            return;
        }
        $names = [];
        $destName = JIT\OperandName::resolve($destOp);
        if (null !== $destName && '' !== $destName) {
            $names[$destName] = true;
        }
        $slot = $block->slotForOperand($destOp);
        if (null !== $slot) {
            foreach ($block->scopedOperands() as $scopeOp) {
                if ($block->slotForOperand($scopeOp) !== $slot) {
                    continue;
                }
                $scopeName = JIT\OperandName::resolve($scopeOp);
                if (null !== $scopeName && '' !== $scopeName) {
                    $names[$scopeName] = true;
                }
            }
        }
        foreach ($names as $name => $_) {
            $name = (string) $name;
            if (\PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if ($this->shouldDeferScriptGlobalForInlineIncludeBinding($name, $destOp, $block)) {
                continue;
            }
            $sg = $this->context->ensureScriptGlobal($name);
            JIT\JitValueBox::assignToPointer(
                $this->context,
                JIT\JitValueBox::valuePtrFromVariable($this->context, $sg),
                $stringVal
            );
            JIT\JitValueBox::publishAfterWrite(
                $this->context,
                JIT\JitValueBox::valuePtrFromVariable($this->context, $sg)
            );
            if (null !== ($stringVal->compileTimeString ?? null)) {
                $sg->compileTimeString = $stringVal->compileTimeString;
            }
            $sg->isNullConstant = false;
            $this->context->bindVariableByName($name, $sg);
            JIT\UndefinedVariableHelper::markAssigned($this->context, $destOp, $sg);
        }
    }

    /**
     * Defer single-use CONCAT temps and flatten in-place `.=` into sequential appends (#36386).
     *
     * php-src: Zend/zend_operators.c ZEND_ASSIGN_CONCAT / zend_string_extend /
     * Zend/zend_string.h zend_print_long_to_buf (via appendInPlaceLong).
     */
    private function tryCompileConcatChainFlatten(
        PHPLLVM\Value $func,
        Block $block,
        OpCode $op,
        int $opIndex
    ): bool {
        if (null === $op->arg1 || null === $op->arg2 || null === $op->arg3) {
            return false;
        }
        $destOp = $block->getOperand($op->arg1);
        $leftOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg2);
        $rightOp = $this->resolveTernaryPhiConcatOperand($block, (int) $op->arg3);
        if (null === $destOp || null === $leftOp || null === $rightOp) {
            return false;
        }
        $destSlotNum = (int) $op->arg1;
        if (isset($this->context->coalesceMergeSlotOperands[$destSlotNum])) {
            return false;
        }
        if ($this->context->coalesceAssignTargets->contains($destOp)) {
            return false;
        }

        $leftLeaves = $this->consumeConcatPendingLeaves($leftOp);
        $rightLeaves = $this->consumeConcatPendingLeaves($rightOp);
        $merged = array_merge($leftLeaves, $rightLeaves);
        $hadPending = \count($merged) > 2
            || \count($leftLeaves) > 1
            || \count($rightLeaves) > 1;
        $inPlace = (int) $op->arg1 === (int) $op->arg2;

        if ($inPlace) {
            if (!$this->context->hasVariableOp($destOp) && !$this->context->hasVariableOp($leftOp)) {
                // Re-store leaves so a later materialize path can still see them.
                if ($hadPending) {
                    $this->concatPendingLeaves[spl_object_id($rightOp)] = $rightLeaves;
                    if (\count($leftLeaves) > 1) {
                        $this->concatPendingLeaves[spl_object_id($leftOp)] = $leftLeaves;
                    }
                }

                return false;
            }
            $result = $this->context->hasVariableOp($destOp)
                ? $this->context->getVariableFromOp($destOp)
                : $this->context->getVariableFromOp($leftOp);
            if (
                null !== $result->writableHt
                || null !== $result->objectPropertySlot
                || null !== $result->staticPropertyGlobal
                || JIT\StringOffsetHelper::isWritableCharOffsetLvalue($result, $this->context)
            ) {
                if ($hadPending) {
                    $newVal = $this->materializeConcatLeaves($merged, $block);
                    $this->assignOperand($destOp, $newVal, true);
                    $this->maybeRefreshIncludeBindingsBeforeUse();

                    return true;
                }

                return false;
            }
            // Simple `$a .= $b` (one leaf each, no pending): keep the existing path.
            if (!$hadPending && 2 === \count($merged)) {
                return false;
            }
            $dest = $this->ensureNativeStringSlotForConcatFlatten($func, $block, $op, $destOp, $result);
            if (null === $dest) {
                if ($hadPending) {
                    $newVal = $this->materializeConcatLeaves($merged, $block);
                    $this->assignOperand($destOp, $newVal, true);
                    $this->maybeRefreshIncludeBindingsBeforeUse();

                    return true;
                }

                return false;
            }
            $this->dropMainScriptStringAliasIfSame($block, $destOp, $dest->value);
            if (
                Variable::TYPE_VALUE === $result->type
                || JIT\JitValueBox::isValueOperand($result)
            ) {
                $this->dropValueBoxStringAliasIfSame($result, $dest->value);
            }
            foreach ($merged as $leafOp) {
                // Skip the in-place left CV itself when it appears as the first leaf
                // (CONCAT($s,$s,$rhs) → leaves are [$s, ...rhs]); appending $s onto $s
                // would alias the buffer during realloc.
                if ($leafOp === $destOp || $leafOp === $leftOp) {
                    if ($leafOp === $merged[0] && $inPlace) {
                        continue;
                    }
                }
                $this->appendConcatLeafToNativeString($dest, $leafOp, $block);
            }
            $dest->compileTimeString = null;
            $this->markScopeVariableAssignedIfTracked($destOp, $dest);
            $this->maybeRefreshIncludeBindingsBeforeUse();

            return true;
        }

        // Non-in-place: defer single-use temps that only feed another CONCAT.
        if ($this->isSingleUseConcatChainTemp($block, $destOp, (int) $op->arg1, $opIndex)) {
            $this->concatPendingLeaves[spl_object_id($destOp)] = $merged;

            return true;
        }

        // Consumed pending leaves but cannot defer — materialize into a fresh string.
        if ($hadPending) {
            $newVal = $this->materializeConcatLeaves($merged, $block);
            $this->assignOperand($destOp, $newVal, true);
            $this->publishMainScriptNamedConcatResult($block, $destOp, $newVal);
            $this->markScopeVariableAssignedIfTracked($destOp, $newVal);
            $this->maybeRefreshIncludeBindingsBeforeUse();

            return true;
        }

        return false;
    }

}
