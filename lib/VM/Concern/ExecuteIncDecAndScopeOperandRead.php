<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmIncDec;

/**
 * VM ++/-- execution, hooked/magic property RMW, and scope-operand reads (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code executeArrayAccessByValueOffsetIncDec}
 * through {@code executeHookedPropertyIncDec} (php-src Zend/zend_vm_def.h INC/DEC,
 * zend_execute.c get_property_ptr_ptr / hooked property RMW, ZEND_CHECK_UNDEFINED_VAR
 * scope reads). Concern trait — same namespace as parent so relative Frame / OpCode /
 * Block helpers resolve. Move-only; no new C ABI.
 */
trait ExecuteIncDecAndScopeOperandRead
{
    private function executeArrayAccessByValueOffsetIncDec(
        Frame $frame,
        Variable $read,
        Variable $result,
        string $className,
        bool $increment,
        bool $prefix
    ): ?Frame {
        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
        $this->context->errors->indirectModificationOfOverloadedElement(
            $className,
            $this->context,
            $frame,
            $scriptFile
        );
        $working = new Variable();
        $working->copyFrom($read->resolveIndirect());
        try {
            if ($prefix) {
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                $result->copyFrom($working);
            } else {
                $old = new Variable();
                $old->copyFrom($working);
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                $result->copyFrom($old);
            }
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /**
     * Store ++/-- result for magic-overloaded props: __set, or deprecated dynamic (#32016).
     *
     * @return null|Frame catch frame on visibility Error
     */
    private function commitMagicOverloadedPropertyIncDecWrite(
        ObjectEntry $owner,
        string $propName,
        Variable $write,
        Variable $working,
        Frame $frame,
        bool $writeUsesMagic,
        bool $isMagicSetProxy
    ): ?Frame {
        if ($writeUsesMagic) {
            $this->invokeMagicSet($owner, $propName, $working);

            return null;
        }
        if ($isMagicSetProxy) {
            $this->writeDynamicPropertyForMagicGetOnlyIncDec($owner, $propName, $working, $frame);

            return null;
        }
        $catchFrame = $this->enforcePropertyVisibilityWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforcePropertyWriteVisibility($owner, $propName, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $write->copyFrom($working);

        return null;
    }

    /** __get without __set: ++/-- writes a deprecated dynamic property (#32016, zend_object_handlers.c). */
    private function writeDynamicPropertyForMagicGetOnlyIncDec(
        ObjectEntry $object,
        string $name,
        Variable $value,
        Frame $frame
    ): void {
        if (!$object->class->allowsDynamicProperties) {
            if (\PHPCompiler\CompilerVersion::supportsDynamicPropertyCreationDeprecation()) {
                $scriptPath = $frame->scriptPath;
                $this->context->errors->deprecatedDynamicProperty(
                    $object->class->name,
                    $name,
                    '' !== $scriptPath && '-' !== $scriptPath ? $scriptPath : null,
                    $this->context,
                    $frame
                );
            }
        }
        $slot = $object->hasProperty($name)
            ? $object->getProperty($name)
            : $object->allocateProperty($name);
        $slot->copyFrom($value);
    }

    /**
     * Pre/post increment/decrement with Zend bool→int coercion (#4727, #3552).
     * Rejects ++/-- on readonly properties after construction (#3149).
     * Inaccessible / overloaded props RMW via __get then __set (#25687, zend_object_handlers.c).
     */
    private function executeIncDec(Frame $frame, OpCode $op, bool $increment, bool $prefix): ?Frame
    {
        $catchFrame = $this->dispatchThisReassignFatalIfNeeded($frame, $op->arg3);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $read = $frame->scope[$op->arg2];
        $write = $frame->scope[$op->arg3];
        $result = $frame->scope[$op->arg1];
        if ($this->canUseIncDecSimpleLocalFastPath($frame, $op, $write)) {
            if ($this->tryExecuteIncDecFastPath($frame, $read, $write, $result, $increment, $prefix, (int) $op->arg3)) {
                return null;
            }
        }
        $catchFrame = $this->enforceReadonlyPropertyWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceFinalPropertyWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceAsymmetricPropertyWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $magicCatch = $this->executeMagicOverloadedPropertyIncDec(
            $frame,
            $read,
            $write,
            $result,
            $increment,
            $prefix
        );
        if (false !== $magicCatch) {
            return $magicCatch;
        }
        $resolvedWrite = $write->resolveIndirect();
        if (
            $resolvedWrite->isArrayAccessOffset()
            && !$this->arrayAccessOffsetGetReturnsByRef(
                $resolvedWrite->getArrayAccessDimension()->getObject()
            )
        ) {
            return $this->executeArrayAccessByValueOffsetIncDec(
                $frame,
                $read,
                $result,
                $resolvedWrite->arrayAccessOffsetClassName(),
                $increment,
                $prefix
            );
        }
        $this->warnUndefinedVariableForIncDecRead($frame, $op, $read, $write);
        $resolvedRead = $read->resolveIndirect();
        $hookedRead = Variable::TYPE_ARRAY === $resolvedRead->type
            ? null
            : $this->fetchHookedPropertyValueForIncDec($write, $frame);
        if (null === $hookedRead && Variable::TYPE_ARRAY !== $resolvedRead->type) {
            $catchFrame = $this->enforceWriteOnlyVirtualPropertyReadForLvalue($write, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        if (null !== $hookedRead) {
            return $this->executeHookedPropertyIncDec(
                $frame,
                $hookedRead,
                $write,
                $result,
                $increment,
                $prefix
            );
        }
        if (Variable::TYPE_STRING_OFFSET === $write->resolveIndirect()->type) {
            return $this->dispatchVmError(Variable::STRING_OFFSET_INCDEC_ERROR, $frame);
        }
        if ($this->tryExecuteIncDecFastPath($frame, $read, $write, $result, $increment, $prefix, (int) $op->arg3)) {
            return null;
        }
        $working = $this->incDecScratch(0);
        $working->copyFrom($read->resolveIndirect());
        try {
            if ($prefix) {
                $before = $this->incDecScratch(1);
                $before->copyFrom($working);
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                // Typed int property: keep MAX/MIN and TypeError (zend_execute.c, #29144).
                VmIncDec::throwIfTypedPropertyRejectsOverflow($write, $before, $working, $increment);
                $write->copyFrom($working);
                $result->copyFrom($working);
            } else {
                $old = $this->incDecScratch(1);
                $old->copyFrom($working);
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                VmIncDec::throwIfTypedPropertyRejectsOverflow($write, $old, $working, $increment);
                $write->copyFrom($working);
                $result->copyFrom($old);
            }
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        $this->markScopeSlotInitialized($frame, (int) $op->arg3);

        return null;
    }

    private function incDecScratch(int $which): Variable
    {
        if (0 === $which) {
            if (null === $this->incDecScratchWorking) {
                $this->incDecScratchWorking = new Variable();
            }

            return $this->incDecScratchWorking;
        }
        if (null === $this->incDecScratchBefore) {
            $this->incDecScratchBefore = new Variable();
        }

        return $this->incDecScratchBefore;
    }

    /**
     * In-place ++/-- for plain locals/refs — no per-op Variable heap churn (#15906, #36148).
     */
    /**
     * ++/-- on an initialized simple local (same read/write slot) — skip property/magic guards (#36411).
     */
    private function canUseIncDecSimpleLocalFastPath(Frame $frame, OpCode $op, Variable $write): bool
    {
        if ((int) $op->arg2 !== (int) $op->arg3) {
            return false;
        }
        if (!isset($frame->initializedSlots[(int) $op->arg3])) {
            return false;
        }
        if (!$this->isSimpleVariableIncDecLvalue($write)) {
            return false;
        }
        $resolved = $write->resolveIndirect();
        if (Variable::TYPE_STRING_OFFSET === $resolved->type || $resolved->isArrayAccessOffset()) {
            return false;
        }

        return true;
    }

    private function tryExecuteIncDecFastPath(
        Frame $frame,
        Variable $read,
        Variable $write,
        Variable $result,
        bool $increment,
        bool $prefix,
        int $writeSlot
    ): bool {
        $resolvedWrite = $write->resolveIndirect();
        if (Variable::TYPE_STRING_OFFSET === $resolvedWrite->type) {
            return false;
        }
        if ($resolvedWrite->isArrayAccessOffset()) {
            return false;
        }
        if (
            Variable::TYPE_INTEGER === $resolvedWrite->type
            && VmIncDec::typedSlotRejectsOverflowDouble($resolvedWrite)
        ) {
            return false;
        }
        $target = $resolvedWrite;
        try {
            if ($prefix) {
                if ($increment) {
                    $target->applyIncrement($this, $frame);
                } else {
                    $target->applyDecrement($this, $frame);
                }
                if ($write !== $target) {
                    $write->copyFrom($target);
                }
                if ($result !== $target && $result !== $write) {
                    $result->copyFrom($target);
                }
            } else {
                $result->copyFrom($target);
                if ($increment) {
                    $target->applyIncrement($this, $frame);
                } else {
                    $target->applyDecrement($this, $frame);
                }
                if ($write !== $target) {
                    $write->copyFrom($target);
                }
            }
        } catch (\TypeError|\Error) {
            return false;
        }
        $this->markScopeSlotInitialized($frame, $writeSlot);

        return true;
    }

    /**
     * ++/-- on undeclared or inaccessible props: __get then __set (zend_std_*_property; #25687).
     *
     * @return null|Frame|false null on success, Frame on catch, false when not a magic RMW lvalue
     */
    private function executeMagicOverloadedPropertyIncDec(
        Frame $frame,
        Variable $read,
        Variable $write,
        Variable $result,
        bool $increment,
        bool $prefix
    ): null|Frame|false {
        $owner = $this->resolvePropertyWriteOwner($write);
        $propName = $this->resolvePropertyWriteName($write);
        if (null === $owner || null === $propName) {
            return false;
        }
        $resolvedWrite = $write->resolveIndirect();
        $isMagicSetProxy = null !== $resolvedWrite->magicSetTarget && null !== $resolvedWrite->magicSetName;
        $readUsesMagic = $this->propertyReadUsesMagicGet($owner, $propName, $frame);
        $writeUsesMagic = $this->propertyWriteUsesMagicSet($owner, $propName, $frame);
        $meta = $this->classPropertyMeta($owner, $propName, $frame);
        $declaredInaccessible = null !== $meta && (
            $this->declaredPropertyInaccessibleFromCaller($owner, $meta, $propName, $frame, $meta->getVisibility)
            || $this->declaredPropertyInaccessibleFromCaller($owner, $meta, $propName, $frame, 0)
        );
        if (!$isMagicSetProxy && !$declaredInaccessible && !$readUsesMagic && !$writeUsesMagic) {
            return false;
        }
        // Accessible declared slot — keep normal in-place mutate even if __get/__set exist.
        if (null !== $meta && !$declaredInaccessible && !$isMagicSetProxy) {
            return false;
        }

        $working = new Variable();
        if ($readUsesMagic) {
            $working->copyFrom($this->invokeMagicGet($owner, $propName));
        } elseif ($declaredInaccessible || $isMagicSetProxy) {
            // Inaccessible / overloaded without __get: Error (do not read the private slot).
            $catchFrame = $this->enforcePropertyVisibilityRead($owner, $propName, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            // Undeclared without __get: fall back to the fetched value (undefined → null/warn elsewhere).
            $working->copyFrom($read->resolveIndirect());
        } else {
            $working->copyFrom($read->resolveIndirect());
        }

        try {
            if ($prefix) {
                $before = new Variable();
                $before->copyFrom($working);
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                if (!$writeUsesMagic) {
                    VmIncDec::throwIfTypedPropertyRejectsOverflow($write, $before, $working, $increment);
                }
                $catchFrame = $this->commitMagicOverloadedPropertyIncDecWrite(
                    $owner,
                    $propName,
                    $write,
                    $working,
                    $frame,
                    $writeUsesMagic,
                    $isMagicSetProxy
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $result->copyFrom($working);
            } else {
                $old = new Variable();
                $old->copyFrom($working);
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                if (!$writeUsesMagic) {
                    VmIncDec::throwIfTypedPropertyRejectsOverflow($write, $old, $working, $increment);
                }
                $catchFrame = $this->commitMagicOverloadedPropertyIncDecWrite(
                    $owner,
                    $propName,
                    $write,
                    $working,
                    $frame,
                    $writeUsesMagic,
                    $isMagicSetProxy
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $result->copyFrom($old);
            }
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /**
     * Zend E_WARNING when ++/-- reads an unbound CV (zend_variables.c, issue #6800).
     */
    private function warnUndefinedVariableForIncDecRead(
        Frame $frame,
        OpCode $op,
        Variable $read,
        Variable $write
    ): void {
        if (!$this->isSimpleVariableIncDecLvalue($write)) {
            return;
        }
        if (!$this->isUnboundVariableIncDecRead($frame, $op, $read)) {
            return;
        }
        $name = $this->resolveScopeSlotVariableName($frame, (int) $op->arg2)
            ?? $this->resolveScopeSlotVariableName($frame, (int) $op->arg3);
        if (null === $name) {
            return;
        }
        $this->context->errors->undefinedVariable(
            $name,
            $this->context,
            $frame,
            '' !== $frame->scriptPath ? $frame->scriptPath : null
        );
    }

    private function isUnboundVariableIncDecRead(Frame $frame, OpCode $op, Variable $read): bool
    {
        $resolved = $read->resolveIndirect();
        if ($resolved->isUndefined()) {
            return true;
        }
        $globalName = $this->context->globalNameForStorage($resolved);
        if (null !== $globalName) {
            return !$this->context->isGlobalEverAssigned($globalName);
        }
        $staticKey = $this->context->functionStaticKeyForStorage($resolved);
        if (null !== $staticKey) {
            return !$this->isFunctionStaticInitializedForFrame($frame, $staticKey);
        }

        return !isset($frame->initializedSlots[(int) $op->arg2]);
    }

    /**
     * Zend E_WARNING when a user-function local is read before assignment (#5454).
     */
    private function warnUndefinedVariableForScopeRead(Frame $frame, int $slot): void
    {
        if (!$this->isUnboundLocalScopeRead($frame, $slot)) {
            return;
        }
        $name = $this->resolveScopeSlotVariableName($frame, $slot);
        if (null === $name) {
            return;
        }
        $this->context->errors->undefinedVariable(
            $name,
            $this->context,
            $frame,
            '' !== $frame->scriptPath ? $frame->scriptPath : null
        );
    }

    /** Zend ZEND_CHECK_UNDEFINED_VAR on scope slot reads (casts/unary/binary/?? RHS, #10358). */
    private function guardUndefinedVariableScopeReadSlot(Frame $frame, int $slot): void
    {
        if (isset($frame->block->constants[$slot])) {
            return;
        }
        $this->warnUndefinedVariableForScopeRead($frame, $slot);
    }

    /**
     * Scope operand for value reads — warn then treat unbound TYPE_UNDEFINED as null (Zend, #10358).
     */
    private function readScopeOperandForRuntimeRead(Frame $frame, int $slot): Variable
    {
        $this->guardUndefinedVariableScopeReadSlot($frame, $slot);
        $operand = $frame->scope[$slot];
        if ($this->isUnboundLocalScopeRead($frame, $slot)) {
            $resolved = $operand->resolveIndirect();
            if ($resolved->isUndefined()) {
                $null = new Variable();
                $null->null();

                return $null;
            }
        }

        return $operand;
    }

    /**
     * Literal constant slots may alias branch-assigned CVs — prefer initialized runtime (#10430, #9973).
     */
    private function readRuntimeOperandPreferringInitializedCv(Frame $frame, int $slot): Variable
    {
        if (isset($frame->block->constants[$slot])) {
            if (isset($frame->scope[$slot])) {
                $local = $frame->scope[$slot]->resolveIndirect();
                if (!$local->isUndefined() && !$this->isUnboundLocalScopeRead($frame, $slot)) {
                    return $this->readScopeOperandForRuntimeRead($frame, $slot);
                }
            }
            $calleeFunc = $frame->block->func;
            for ($f = $frame->parent; null !== $f; $f = $f->parent) {
                // Callee concat must not read caller CVs when slot indices collide (#17383, re-#16253).
                if ($f->block->func !== $calleeFunc) {
                    break;
                }
                if (!isset($f->scope[$slot])) {
                    continue;
                }
                $resolved = $f->scope[$slot]->resolveIndirect();
                if ($resolved->isUndefined() || $this->isUnboundLocalScopeRead($f, $slot)) {
                    continue;
                }

                return $this->readScopeOperandForRuntimeRead($f, $slot);
            }

            return $frame->block->constants[$slot];
        }

        return $this->readScopeOperandForRuntimeRead($frame, $slot);
    }

    /**
     * True when the current block produced a FETCH_DIM_W into $destSlot before the ASSIGN
     * now executing (pos already advanced past the ASSIGN). Used to keep real dim writes
     * while clearing stale FETCH_DIM (read) indirects after temp-slot reuse (#36380).
     */
    private function assignDestKeptAsWriteThrough(Frame $frame, int $destSlot): bool
    {
        $ops = $frame->block->opCodes;
        for ($i = $frame->pos - 2; $i >= 0; --$i) {
            $prev = $ops[$i] ?? null;
            if (null === $prev) {
                break;
            }
            if (OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $prev->type && (int) $prev->arg1 === $destSlot) {
                return true;
            }
            if (OpCode::TYPE_ASSIGN_REF === $prev->type && (int) $prev->arg1 === $destSlot) {
                return true;
            }
            if (
                (OpCode::TYPE_JUMP === $prev->type || OpCode::TYPE_JUMPIF === $prev->type)
            ) {
                break;
            }
            if (OpCode::TYPE_ARRAY_DIM_FETCH === $prev->type && (int) $prev->arg1 === $destSlot) {
                // Read fetch — not a write lvalue for this ASSIGN.
                return false;
            }
        }

        return false;
    }

    /**
     * Dim-key operand for FETCH_DIM / FETCH_DIM_W (#36380).
     *
     * Compile-time string/int keys are stored in {@see Block::$constants}. The same slot
     * integer can later hold a CV that was assigned an array (Parsedown builds `$Data`
     * then writes `$this->DefinitionData['Reference'][$id] = $Data`). Prefer the constant
     * when scope is missing, unbound, or not a legal array offset — otherwise keep the
     * initialized CV (#10430).
     *
     * php-src: Zend/zend_execute.c {@code zend_fetch_dimension_address} / IS_CONST keys.
     */
    private function readDimKeyOperand(Frame $frame, int $slot): Variable
    {
        if (!isset($frame->block->constants[$slot])) {
            return $this->readScopeOperandForRuntimeRead($frame, $slot);
        }
        $const = $frame->block->constants[$slot];
        if (isset($frame->scope[$slot])) {
            $local = $frame->scope[$slot]->resolveIndirect();
            if (
                !$local->isUndefined()
                && !$this->isUnboundLocalScopeRead($frame, $slot)
                && self::variableIsLegalArrayDimKey($local)
            ) {
                return $this->readScopeOperandForRuntimeRead($frame, $slot);
            }
        }

        return $const;
    }

    /** True when {@see $var} is a Zend-legal array offset type (not array/object). */
    private static function variableIsLegalArrayDimKey(Variable $var): bool
    {
        return match ($var->type) {
            Variable::TYPE_NULL,
            Variable::TYPE_BOOLEAN,
            Variable::TYPE_INTEGER,
            Variable::TYPE_FLOAT,
            Variable::TYPE_STRING => true,
            default => false,
        };
    }

    /** TYPE_CONCAT operands may be literal constant slots colliding with assign dest (#9973, #9063). */
    private function readRuntimeOperandForConcat(Frame $frame, int $slot): Variable
    {
        return $this->readRuntimeOperandPreferringInitializedCv($frame, $slot);
    }

    /** Bitwise ops in CFG branch blocks may inherit polluted literal slots (#15902). */
    private function readRuntimeOperandForBitwise(Frame $frame, int $slot): Variable
    {
        if (isset($frame->block->constants[$slot])) {
            $copy = new Variable();
            $copy->duplicateFrom($frame->block->constants[$slot]);

            return $copy;
        }

        return $this->readScopeOperandForRuntimeRead($frame, $slot);
    }

    private function isUnboundLocalScopeRead(Frame $frame, int $slot): bool
    {
        if (!isset($frame->scope[$slot])) {
            return false;
        }
        if (isset($frame->initializedSlots[$slot])) {
            return false;
        }
        if (null !== $frame->catchVarSlot && $slot === $frame->catchVarSlot) {
            return false;
        }
        $name = $this->resolveScopeSlotVariableName($frame, $slot);
        if (null === $name || 'this' === $name) {
            return false;
        }
        $resolved = $frame->scope[$slot]->resolveIndirect();
        if ($resolved->isUndefined()) {
            return true;
        }
        $globalName = $this->context->globalNameForStorage($resolved);
        if (null !== $globalName) {
            return !$this->context->isGlobalEverAssigned($globalName);
        }
        $staticKey = $this->context->functionStaticKeyForStorage($resolved);
        if (null !== $staticKey) {
            return !$this->isFunctionStaticInitializedForFrame($frame, $staticKey);
        }
        if (null === $frame->block || $frame->block->inheritUndefinedLocals) {
            // ErrorSuppress / CFG branch blocks inherit parent CVs so unnamed temps
            // do not warn. Named locals still need ZEND_CHECK_UNDEFINED_VAR when `@`
            // is active so silenced `$undef` records error_get_last() (#32041).
            if (
                $this->context->errors->isSilenced()
                && Variable::TYPE_NULL === $resolved->type
            ) {
                return !isset($frame->initializedSlots[$slot]);
            }

            return false;
        }
        if (null !== $name && $frame->block->declaresGlobalName($name)) {
            return false;
        }
        // Zend ZEND_CHECK_UNDEFINED_VAR: assigned CVs (extract imports, etc.) are readable (#10590).
        if (Variable::TYPE_NULL !== $resolved->type) {
            return false;
        }

        return !isset($frame->initializedSlots[$slot]);
    }

    private function markScopeSlotInitialized(Frame $frame, int $slot): void
    {
        if (isset($frame->initializedSlots[$slot])) {
            return;
        }
        $frame->initializedSlots[$slot] = true;
        if (!isset($frame->scope[$slot])) {
            return;
        }
        $globalName = $this->context->globalNameForStorage($frame->scope[$slot]->resolveIndirect());
        if (null !== $globalName) {
            $this->context->markGlobalEverAssigned($globalName);
        }
    }

    /** Mark CV init when a binary op writes directly into a named local slot (#9063). */
    private function markScopeSlotInitializedIfNamedLocal(Frame $frame, int $slot): void
    {
        if (null === $this->resolveScopeSlotVariableName($frame, $slot)) {
            return;
        }
        $this->markScopeSlotInitialized($frame, $slot);
    }

    private function resolveScopeSlotVariableName(Frame $frame, int $slot): ?string
    {
        $operand = $frame->block->operandForScopeSlot($slot);

        return null !== $operand ? Block::resolveVariableName($operand) : null;
    }

    private function isSimpleVariableIncDecLvalue(Variable $write): bool
    {
        if (null !== $this->resolvePropertyWriteOwner($write)) {
            return false;
        }
        $target = $write->resolveIndirect();
        if (Variable::TYPE_STRING_OFFSET === $target->type) {
            return false;
        }
        $classLc = $write->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        if (is_string($classLc) && '' !== $classLc) {
            return false;
        }

        return true;
    }

    /**
     * Read via get hook for ++/-- on hooked static or instance properties (#6319, zend_property_hooks.c).
     */
    private function fetchHookedPropertyValueForIncDec(Variable $write, Frame $frame): ?Variable
    {
        if ($this->isPropertyHookRawWrite($frame, $this->resolvePropertyWriteName($write) ?? '')) {
            return null;
        }
        $target = $write->resolveIndirect();
        $classLc = $write->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $write->objectPropertyName ?? $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName));
            $getLc = $hooks['get'] ?? null;
            if (null === $getLc) {
                return null;
            }

            return $this->fetchStaticPropertyWithHooks($classLc, $staticPropName, $getLc, $frame);
        }
        $owner = $this->resolvePropertyWriteOwner($write);
        $propName = $this->resolvePropertyWriteName($write);
        if (null === $owner || null === $propName) {
            return null;
        }

        return $this->fetchPropertyWithHooks($owner, $propName, $frame);
    }

    /**
     * In-place compound assign on hooked properties ($prop .= 'x', $prop += 1) (#6438, zend_property_hooks.c).
     */
    private function executeHookedPropertyInPlaceCompound(Frame $frame, OpCode $op, Variable $hookedRead): ?Frame
    {
        $write = $frame->scope[$op->arg1];
        $working = new Variable();
        $working->copyFrom($hookedRead->resolveIndirect());
        try {
            switch ($op->type) {
                case OpCode::TYPE_CONCAT:
                    $lhs = $this->coerceVariableToString($working, $frame);
                    $rhs = $this->coerceVariableToString($frame->scope[$op->arg3], $frame);
                    $working->string($lhs . $rhs);
                    break;
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_POW:
                    $working->numericOp($op->type, $working, $frame->scope[$op->arg3], $this, $frame);
                    break;
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                    $working->bitwiseOp($op->type, $working, $frame->scope[$op->arg3], $this, $frame);
                    break;
                default:
                    return null;
            }
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        } catch (\DivisionByZeroError $e) {
            return $this->dispatchVmDivisionByZeroError($e, $frame);
        } catch (\ArithmeticError $e) {
            return $this->dispatchVmArithmeticError($e, $frame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }
        $catchFrame = $this->enforceAsymmetricPropertyWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        if (!$this->dispatchPropertySetHookAssign($write, $working, $frame)) {
            $catchFrame = $this->enforceVirtualPropertyHookWrite($write, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $write->copyFrom($working);
        }

        return null;
    }

    private function executeHookedPropertyIncDec(
        Frame $frame,
        Variable $hookedRead,
        Variable $write,
        Variable $result,
        bool $increment,
        bool $prefix
    ): ?Frame {
        $working = new Variable();
        $working->copyFrom($hookedRead->resolveIndirect());
        try {
            if ($prefix) {
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                $catchFrame = $this->enforceAsymmetricPropertyWrite($write, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                if (!$this->dispatchPropertySetHookAssign($write, $working, $frame)) {
                    $catchFrame = $this->enforceVirtualPropertyHookWrite($write, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $write->copyFrom($working);
                }
                $result->copyFrom($working);

                return null;
            }
            $old = new Variable();
            $old->copyFrom($working);
            if ($increment) {
                $working->applyIncrement($this, $frame);
            } else {
                $working->applyDecrement($this, $frame);
            }
            $catchFrame = $this->enforceAsymmetricPropertyWrite($write, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            if (!$this->dispatchPropertySetHookAssign($write, $working, $frame)) {
                $catchFrame = $this->enforceVirtualPropertyHookWrite($write, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $write->copyFrom($working);
            }
            $result->copyFrom($old);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        }

        return null;
    }
}
