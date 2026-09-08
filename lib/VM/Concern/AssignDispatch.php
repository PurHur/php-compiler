<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\DnfCheck;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

/**
 * VM TYPE_ASSIGN / TYPE_ASSIGN_REF dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner assign case body
 * (php-src Zend/zend_vm_def.h ZEND_ASSIGN / ZEND_ASSIGN_REF /
 * zend_assign_to_variable / zend_assign_to_variable_reference). Concern trait —
 * same namespace as parent so relative Frame / OpCode helpers resolve. Move-only;
 * no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait AssignDispatch
{
    /**
     * Execute TYPE_ASSIGN for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeAssignDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $catchFrame = $this->dispatchThisReassignFatalIfNeeded($frame, $op->arg2);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        if (!isset($frame->block->constants[$op->arg3])) {
            $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg3);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $arg1 = $frame->scope[$op->arg1];
        $arg2 = $frame->scope[$op->arg2];
        // Stale FETCH_DIM (read) indirect after temp-slot reuse: writing `$cond = $bool`
        // must not punch through into `$Block['data']['type']` (#36380 Parsedown lists).
        // Keep write-through for FETCH_DIM_W lvalues, property lvalues, and explicit
        // PHP references (`$r =& …` / foreach-by-ref — {@see Variable::$phpReference}).
        $assignDestSlot = (int) $op->arg2;
        $keepWriteThrough = $this->assignDestKeptAsWriteThrough($frame, $assignDestSlot);
        if (
            $arg2->isIndirect()
            && !$arg2->phpReference
            && !$arg2->propertyAssignLvalue
            && !$keepWriteThrough
        ) {
            $arg2->reset();
        }
        // Hash-table bucket cells must never be the ASSIGN destination Variable object
        // itself unless this is a real FETCH_DIM_W lvalue — multi-arg nested isset
        // recycled the `$m[0] = …` write cell as the isset result slot and turned
        // `$m[0]` into bool (#36398).
        if ($arg2->hashTableBucketCell && !$keepWriteThrough) {
            $fresh = new Variable();
            $frame->scope[$assignDestSlot] = $fresh;
            if ((int) $op->arg1 === $assignDestSlot) {
                $arg1 = $fresh;
            }
            $arg2 = $fresh;
        }
        // Boolean/null sources are never dim write-backs: always break stale
        // indirection before copyFrom write-through (#36398 isset result).
        if (null !== $op->arg3) {
            $srcPeek = isset($frame->block->constants[$op->arg3])
                ? $frame->block->constants[$op->arg3]
                : $frame->scope[(int) $op->arg3];
            $srcPeek = $srcPeek->resolveIndirect();
            if (
                (Variable::TYPE_BOOLEAN === $srcPeek->type || Variable::TYPE_NULL === $srcPeek->type)
                && $arg2->isIndirect()
                && !$arg2->phpReference
                && !$arg2->propertyAssignLvalue
            ) {
                $fresh = new Variable();
                $frame->scope[$assignDestSlot] = $fresh;
                if ((int) $op->arg1 === $assignDestSlot) {
                    $arg1 = $fresh;
                }
                $arg2 = $fresh;
            }
        }
        if (null !== $op->arg3) {
            $arg3 = isset($frame->block->constants[$op->arg3])
                ? $frame->block->constants[$op->arg3]
                : $this->readRuntimeOperandPreferringInitializedCv($frame, (int) $op->arg3);
        } else {
            // ?: merge assigns omit arg3; legacy lowering reads slot 0 (#9159, re-#14134).
            $arg3 = $this->readScopeOperandForRuntimeRead($frame, 0);
        }
        // Direct `$obj->prop =` (propertyAssignLvalue) checks visibility. Writes through
        // an already-acquired reference (`$r =& …; $r =`) must not — Zend (#29456).
        if ($arg2->propertyAssignLvalue) {
            $catchFrame = $this->enforcePropertyVisibilityWrite($arg2, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $catchFrame = $this->enforceStaticPropertyVisibilityWrite($arg2, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $catchFrame = $this->enforceReadonlyPropertyWrite($arg2, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceFinalPropertyWrite($arg2, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceAsymmetricPropertyWrite($arg2, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->emitPropertyWriteDeprecation($arg2, $frame);
        try {
            if (
                !$this->assignDefersHookedPropertyDimWriteBack($arg2)
                && $this->dispatchPropertySetHookAssign($arg2, $arg3, $frame)
            ) {
                $this->deliverPropertySetHookAssignResult($arg1, $arg3);
                return null;
            }
        } catch (VM\PropertyHookRefWriteSignal $signal) {
            return $signal->catchFrame;
        }
        if ($this->context->propertyHookSetAborted) {
            $this->context->propertyHookSetAborted = false;
            return null;
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($arg2, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $writeTarget = $arg2->resolveIndirect();
        if (null !== $writeTarget->magicSetTarget && null !== $writeTarget->magicSetName) {
            $this->invokeMagicSet($writeTarget->magicSetTarget, $writeTarget->magicSetName, $arg3);
            $arg1->copyFrom($arg3);
            return null;
        }
        if (null !== $writeTarget->arrayAsPropsTarget && null !== $writeTarget->arrayAsPropsName) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($writeTarget->arrayAsPropsName);
            VM\SplArraySupport::offsetSet($writeTarget->arrayAsPropsTarget, $key, $arg3);
            $arg1->copyFrom($arg3);
            return null;
        }
        if (null !== ($msg = $this->asymmetricPropertyWriteMessage($arg2, $frame))) {
            $catchFrame = $this->dispatchVmError($msg, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $writeTarget = $arg2->resolveIndirect();
        if (
            $this->context->isGlobalStorage($writeTarget)
            && !VM\EnumCaseSupport::arrayContainsRuntimeRefs($arg3)
        ) {
            $resolvedArg = $arg3->resolveIndirect();
            if (!$resolvedArg->isUndefined()) {
                $stored = VM\EnumCaseSupport::materializeGlobalVariableValue($this->context, $arg3);
                $arg2->copyFrom($stored);
                $arg1->copyFrom($stored);
                // materializeGlobalVariableValue returns a non-scope Variable; its
                // object/array ref must be dropped or script-global assign leaks and
                // defers __destruct until shutdown (#23484, re-#6456).
                $stored->reset();
            } else {
                $arg2->copyFrom($arg3);
                $arg1->copyFrom($arg3);
            }
        } else {
            $catchFrame = $this->assignCopyFrom($arg2, $arg3, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $arg1->copyFrom($arg3);
        }
        $catchFrame = $this->flushHookedPropertyDimWriteBackAfterAssign($arg2, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        VM\DomVmRuntimeSupport::retainUserHandleFromVariable($arg2);
        if (
            !$this->shouldDeferVmDeadTempRelease($frame)
            && $op->arg2 !== $op->arg3
            && $frame->block->assignTempSlotIsDead((int) $op->arg3)
        ) {
            $this->releaseVmDeadScopeSlot($frame, (int) $op->arg3);
        }
        if (
            !$this->shouldDeferVmDeadTempRelease($frame)
            && $op->arg1 !== $op->arg2
            && $op->arg1 !== $op->arg3
            && $frame->block->assignTempSlotIsDead((int) $op->arg1)
        ) {
            $this->releaseVmDeadScopeSlot($frame, (int) $op->arg1);
        }
        $strict = null !== $frame->parent
            ? $frame->parent->block->strictTypes
            : $frame->block->strictTypes;
        try {
            TypeCheck::coercePropertyWrite($arg2, $strict);
            if (null !== $writeTarget->dnfArms) {
                $dnfCtx = $this->context;
                $viaRef = TypeCheck::destIsTypedPropertyByRefWrite($arg2);
                TypeCheck::withTypedPropertyByRefAssign(
                    $viaRef,
                    static function () use ($arg3, $writeTarget, $dnfCtx, $strict): void {
                        DnfCheck::assertMatches(
                            $arg3,
                            $writeTarget->dnfArms,
                            $dnfCtx,
                            'Property',
                            $writeTarget,
                            $strict
                        );
                    }
                );
            }
        } catch (\TypeError $e) {
            $catchFrame = $this->dispatchVmTypeError($e, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $this->markScopeSlotInitialized($frame, (int) $op->arg2);
        $this->releaseVmStatementDeadTemps($frame, (int) $op->arg2);
        return null;
    }

    /**
     * Execute TYPE_ASSIGN_REF for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeAssignRefDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $catchFrame = $this->dispatchThisReassignFatalIfNeeded($frame, $op->arg1);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        if (null !== $op->arg3 && 1 === (int) $op->arg3) {
            $catchFrame = $this->dispatchVmError(
                'Cannot assign reference to non referenceable value',
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        $lhs = $frame->scope[$op->arg1];
        $catchFrame = $this->enforcePropertyVisibilityWrite($lhs, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceStaticPropertyVisibilityWrite($lhs, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceReadonlyPropertyWrite($lhs, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceFinalPropertyWrite($lhs, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        if (null !== ($msg = $this->asymmetricPropertyWriteMessage($lhs, $frame))) {
            $catchFrame = $this->dispatchVmError($msg, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $this->emitPropertyWriteDeprecation($lhs, $frame);
        $rhsSlot = $frame->scope[$op->arg2];
        // `$r = &$obj->readonlyProp` — also guard here when fetch temp carries owner (#25620).
        $catchFrame = $this->enforceReadonlyPropertyFetchByRef($rhsSlot, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        // `$r = &$obj->uninitTyped` — get_property_ptr_ptr Error / nullable ZVAL_NULL (#31771).
        VM\TypedPropertyCheck::prepareWritableByReference($rhsSlot);
        // Reference acquisition via `$r = &$obj->prop` follows set visibility (#7070).
        // Already-acquired by-ref call returns (`$r = &$obj->getPriv()`) must not
        // re-check — Zend aliases the returned reference (#29456).
        if ($rhsSlot->propertyRefAcquisition) {
            $catchFrame = $this->enforcePropertyVisibilityWrite($rhsSlot, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $catchFrame = $this->enforceStaticPropertyVisibilityWrite($rhsSlot, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            if (null !== ($msg = $this->asymmetricPropertyWriteMessage($rhsSlot, $frame))) {
                $catchFrame = $this->dispatchVmError($msg, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }
        }
        $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $rhs = $rhsSlot->resolveIndirect();
        // ArrayDimFetch / property fetch temps are indirect to live storage; write the
        // reference into that cell instead of redirecting the temp (#5349).
        $lhsPeel = $lhs->isIndirect() ? $lhs->directIndirectTarget() : $lhs;
        // Zend: cannot create references to/from string offsets (#21910).
        if (
            Variable::TYPE_STRING_OFFSET === $rhs->type
            || Variable::TYPE_STRING_OFFSET === $lhsPeel->resolveIndirect()->type
        ) {
            $catchFrame = $this->dispatchVmError(Variable::STRING_OFFSET_REF_ERROR, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        // Zend zend_assign_to_variable_reference: non-variable RHS → Notice + value assign (#30015).
        if (!VM\ReferencableCheck::isReferenceable($rhsSlot, $frame)) {
            VM\ReferencableCheck::emitNonVariableAssignRefNotice($frame);
            $catchFrame = $this->assignCopyFrom($lhs, $rhsSlot, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        if (null !== $lhsPeel->objectPropertyOwner) {
            // $obj->prop =& $v — bind into declared property storage (#5370).
            $writeTarget = $lhsPeel;
        } elseif (null !== $rhs->objectPropertyOwner) {
            // $ref = &$obj->prop — bind variable slot, not peeled global wrapper (#13559).
            // Inline array `[&$obj->hook]` — bind the array bucket behind dim-fetch temp (#17353).
            if (
                $lhs->isIndirect()
                && null === $lhsPeel->objectPropertyOwner
                && !$this->context->isGlobalStorage($lhsPeel)
            ) {
                $writeTarget = $lhsPeel;
            } else {
                $writeTarget = $lhs;
            }
        } else {
            $writeTarget = $lhs->isIndirect() ? $lhsPeel : $lhs;
        }
        // Zend BIND_STATIC + ASSIGN_REF: `$s = &$param` rebinds the CV only; the
        // static_variables HT keeps its prior value and next BIND restores it (#21993).
        if (
            $lhs->isIndirect()
            && null !== $lhsPeel
            && null !== $this->context->functionStaticKeyForStorage($lhsPeel)
        ) {
            $writeTarget = $lhs;
        }
        // Zend ASSIGN_REF: named CV `$a =& $x` rebinds the local symbol only —
        // disconnects by-ref params from the caller, local aliases from their prior
        // referent, and `global $g` inside functions (#22546). Main-script globals
        // still peel into the symbol-table cell so `$GLOBALS` stays linked.
        // Unnamed dim/$GLOBALS fetch temps keep peeling into live storage (#5349).
        if (
            $lhs->isIndirect()
            && $writeTarget === $lhsPeel
            && null !== $lhsPeel
            && null !== $this->resolveScopeSlotVariableName($frame, (int) $op->arg1)
            && (
                !$this->context->isGlobalStorage($lhsPeel)
                || !$frame->block->isMainScript()
            )
        ) {
            $writeTarget = $lhs;
        }
        if (
            null !== $op->arg3
            && OpCode::ASSIGN_REF_FOREACH_PROPERTY_HOOK === (int) $op->arg3
        ) {
            $lhsHookRefLvalue = $this->resolvePropertyHookRefWriteLvalue($lhs, $frame);
            if (null === $lhsHookRefLvalue) {
                $hookTarget = $writeTarget->resolveIndirect();
                $owner = $hookTarget->objectPropertyOwner;
                $propName = $hookTarget->objectPropertyName;
                if (null !== $owner && null !== $propName) {
                    $proxy = new Variable();
                    $proxy->objectPropertyOwner = $owner;
                    $proxy->objectPropertyName = $propName;
                    $lhsHookRefLvalue = $proxy;
                }
            }
            if (null !== $lhsHookRefLvalue) {
                if (!$this->propertyWriteHasSetHook($lhsHookRefLvalue)) {
                    $catchFrame = $this->enforceVirtualPropertyHookWrite($lhsHookRefLvalue, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    return null;
                }
                // Zend FE_FETCH_R: iteration value to hook backing; in-loop writes use hooks (#6435).
                $this->writeHookedPropertyForeachIterationValue(
                    $lhsHookRefLvalue,
                    $rhs,
                    $frame
                );
            }
            return null;
        }
        // Zend: Class::$prop = &Class::$prop stores NULL, not a circular ref (#5405).
        if ($writeTarget === $rhs && $this->isStaticPropertyStorageCell($writeTarget)) {
            $writeTarget->null();
            return null;
        }
        // Zend: `$obj->hooked =& $v` — get_property_ptr_ptr fails for hooked props (#22475).
        $lhsHookAssignLvalue = $this->resolvePropertyHookRefWriteLvalue($lhs, $frame);
        if (null === $lhsHookAssignLvalue) {
            $lhsHookAssignLvalue = $this->resolvePropertyHookRefWriteLvalue($writeTarget, $frame);
        }
        if (null !== $lhsHookAssignLvalue) {
            $catchFrame = $this->dispatchVmError(
                'Cannot assign by reference to overloaded object',
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        // Zend: `$r = &$obj->hooked` requires `&get` (#22475, zend_object_handlers.c).
        // Without `&get`, read_property(BP_VAR_W) still invokes get for side effects,
        // then Errors unless the get result is an object (#29719).
        $hookRefLvalue = $this->resolvePropertyHookRefWriteLvalue($rhsSlot, $frame);
        if (null !== $hookRefLvalue) {
            if (!$this->propertyHookGetIsByRef($hookRefLvalue)) {
                $catchFrame = $this->assignRefFromHookedPropertyWithoutByRefGet(
                    $writeTarget,
                    $hookRefLvalue,
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            $catchFrame = $this->bindAssignRefToByRefGetHook(
                $writeTarget,
                $hookRefLvalue,
                $frame
            );
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        // Object property / static / nested ref slots are live storage (Zend FE_FETCH_R,
        // #5245). Main-script globals use an indirect wrapper — still need a shared ref
        // cell so unset($a) does not destroy $b (#5368).
        // HashTable bucket cells are destroyed with the array: promote to a shared
        // IS_REFERENCE-style cell so `$b =& $a[$k]; unset($a);` keeps the residual (#22027).
        if (null !== $rhs->objectPropertyOwner) {
            $writeTarget->indirectAsPhpReference($rhs);
            $this->markTypedPropertyByRefAlias($writeTarget, $rhs);
            if ($writeTarget !== $lhs) {
                $lhs->indirectAsPhpReference($writeTarget->resolveIndirect());
            } else {
                $lhs->phpReference = true;
            }
            return null;
        }
        if (
            null !== $rhs->staticPropertyClassLc
            && null !== $rhs->objectPropertyName
        ) {
            $writeTarget->indirectAsPhpReference($rhs);
            $this->markTypedPropertyByRefAlias($writeTarget, $rhs);
            if ($writeTarget !== $lhs) {
                $lhs->indirectAsPhpReference($writeTarget->resolveIndirect());
            } else {
                $lhs->phpReference = true;
            }
            return null;
        }
        if (
            $rhsSlot->isIndirect()
            && !$this->context->isGlobalStorage($rhs)
            && !$rhs->hashTableBucketCell
        ) {
            $writeTarget->indirectAsPhpReference($rhs);
            $this->markTypedPropertyByRefAlias($writeTarget, $rhs);
            if ($writeTarget !== $lhs) {
                $lhs->indirectAsPhpReference($writeTarget->resolveIndirect());
            } else {
                $lhs->phpReference = true;
            }
            return null;
        }
        if (Variable::TYPE_INDIRECT !== $rhs->type) {
            $ref = new Variable();
            $ref->copyFrom($rhs);
            $rhs->indirect($ref);
        }
        $writeTarget->indirectAsPhpReference($rhs->resolveIndirect());
        $this->markTypedPropertyByRefAlias($writeTarget, $rhs->resolveIndirect());
        // Named CV may have held a stale dim-read indirect (slot reuse); ensure the
        // CV slot itself is the phpReference wrapper (#36380).
        if ($writeTarget !== $lhs) {
            $lhs->indirectAsPhpReference($writeTarget->resolveIndirect());
        } else {
            $lhs->phpReference = true;
        }
        return null;
    }
}
