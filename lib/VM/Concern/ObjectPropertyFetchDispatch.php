<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

/**
 * VM TYPE_PROPERTY_FETCH / TYPE_PROPERTY_FETCH_WRITE dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner property-fetch case body
 * (php-src Zend/zend_vm_def.h ZEND_FETCH_OBJ_R/W / zend_object_handlers.c
 * read_property / get_property_ptr_ptr). Concern trait — same namespace as parent
 * so relative Frame / OpCode helpers resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ObjectPropertyFetchDispatch
{
    /**
     * Execute PROPERTY_FETCH / PROPERTY_FETCH_WRITE for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executePropertyFetchDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $result = $frame->scope[$op->arg1];
        $propertyFetchForWrite = OpCode::TYPE_PROPERTY_FETCH_WRITE === $op->type;
        $fiber = $this->context->currentFiber;
        if (null !== $fiber?->propertyHookResumeRead) {
            $result->copyFrom($fiber->propertyHookResumeRead->resolveIndirect());
            $fiber->propertyHookResumeRead = null;
            return null;
        }
        $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $var = $frame->scope[$op->arg2]->resolveIndirect();
        [$name, $catchFrame] = $this->coerceRuntimeOperandToString($frame->scope[$op->arg3], $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforcePropertyName($name, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            $enumEntry = $var->toEnumCase()->enumClass;
            $forWrite = $propertyFetchForWrite || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
            if ($forWrite) {
                // Readonly name/value, else Cannot create dynamic property (#26588).
                $writeMsg = EnumCaseSupport::propertyWriteViolationMessage($enumEntry, $name);
                $catchFrame = $this->dispatchVmError($writeMsg, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            try {
                $prop = $var->toEnumCase()->fetchProperty($name, $this->context, $frame);
            } catch (\LogicException $e) {
                return $this->raise($e->getMessage(), $frame);
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            $result->copyFrom($prop);
            return null;
        }
        if (TypeCheck::isNonObjectPropertyFetchReceiver($var)) {
            $resolved = $var->resolveIndirect();
            // zend_zval_value_name — bool prints true/false, not bool (#30054 / #30066).
            $typeName = VM\EnumCaseSupport::typeNameForTypeErrorActual($resolved);
            $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
            $forWrite = $propertyFetchForWrite
                || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op)
                || $this->propertyFetchDestUsedAsReadBeforeAssign($frame, $op);
            if ($forWrite) {
                // ZEND_PRE/POST_INC/DEC_OBJ: verb is increment/decrement for any
                // non-object (null and true/false), not only null (#7431 / #30075).
                if ($this->propertyFetchDestUsedAsIncDec($frame, $op)) {
                    $catchFrame = $this->dispatchVmError(
                        sprintf(
                            'Attempt to increment/decrement property "%s" on %s',
                            $name,
                            $typeName
                        ),
                        $frame
                    );
                } else {
                    $catchFrame = $this->dispatchVmError(
                        sprintf('Attempt to assign property "%s" on %s', $name, $typeName),
                        $frame
                    );
                }
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                return null;
            }
            if ($op->propertyHookCoalesceRead) {
                // ?? / ??= BP_VAR_IS on non-object — silent null (#30120, zend_vm_def.h).
                $result->null();
                return null;
            }
            if ($op->nullsafeFetchPropertyRead) {
                // IS-mode (??/isset/empty) or null: silent like FETCH_OBJ_IS (#18026).
                // R-mode nullsafe on scalar/array: warn like plain -> (#26365).
                if (
                    $op->nullsafeUninitNullableToNull
                    || Variable::TYPE_NULL === $resolved->type
                ) {
                    $result->null();
                    return null;
                }
            } elseif (Variable::TYPE_NULL === $resolved->type) {
                $this->context->errors->propertyReadOnNonObject(
                    $name,
                    'null',
                    $this->context,
                    $frame,
                    $scriptFile
                );
                $result->null();
                return null;
            }
            $this->context->errors->propertyReadOnNonObject(
                $name,
                $typeName,
                $this->context,
                $frame,
                $scriptFile
            );
            $result->null();
            return null;
        }
        $propertyObject = $var->toObject();
        if (!VM\LazyObjectSupport::skipLazyInitForPropertyRead($propertyObject, $name)) {
            $catchFrame = $this->ensureLazyObjectInitialized($propertyObject, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $propertyObject = VM\LazyObjectSupport::getLazyInstance($propertyObject);
        // Static prop via -> / ?->: E_NOTICE then dynamic/undefined (zend_object_handlers.c, #30017).
        // isset / ?? (propertyHookCoalesceRead) are silent; inaccessible protected/private Error.
        $catchFrame = $this->handleStaticPropertyAccessedAsInstance(
            $propertyObject,
            $name,
            $frame,
            $op->propertyHookCoalesceRead
        );
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        // __PHP_Incomplete_Class — block userland property ops (zend_object_handlers.c, #19632).
        if (VM\IncompleteClassSupport::isIncomplete($propertyObject)) {
            $forWrite = $propertyFetchForWrite || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
            if ($forWrite) {
                $catchFrame = $this->dispatchVmError(
                    VM\IncompleteClassSupport::modifyErrorMessage($propertyObject),
                    $frame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            if ($op->nullsafeFetchPropertyRead) {
                $result->null();
                return null;
            }
            VM\IncompleteClassSupport::emitAccessWarning($propertyObject, $this->context, $frame);
            $result->null();
            return null;
        }
        if (VM\ResourceSupport::isResourceObject($propertyObject)) {
            $forWrite = $propertyFetchForWrite
                || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op)
                || $this->propertyFetchDestUsedAsReadBeforeAssign($frame, $op);
            if ($forWrite) {
                if ($this->propertyFetchDestUsedAsIncDec($frame, $op)) {
                    $catchFrame = $this->dispatchVmError(
                        sprintf('Attempt to increment/decrement property "%s" on resource', $name),
                        $frame
                    );
                } else {
                    $catchFrame = $this->dispatchVmError(
                        sprintf('Attempt to assign property "%s" on resource', $name),
                        $frame
                    );
                }
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            if ($op->propertyHookCoalesceRead) {
                $result->null();
                return null;
            }
            $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
            $this->context->errors->propertyReadOnNonObject(
                $name,
                'resource',
                $this->context,
                $frame,
                $scriptFile
            );
            $result->null();
            return null;
        }
        if (EnumCaseSupport::isEnumCase($propertyObject)) {
            $forWrite = $propertyFetchForWrite || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
            if ($forWrite) {
                // Readonly name/value, else Cannot create dynamic property (#26588).
                $writeMsg = EnumCaseSupport::propertyWriteViolationMessage(
                    $propertyObject->class,
                    $name
                );
                $catchFrame = $this->dispatchVmError($writeMsg, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            try {
                $result->copyFrom(EnumCaseSupport::getProperty(
                    $propertyObject,
                    $name,
                    $this->context,
                    $frame
                ));
            } catch (\Error $e) {
                $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }

                return self::EXCEPTION;
            }
            return null;
        }
        $forWrite = $propertyFetchForWrite || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
        $magicGetForRead = !$forWrite
            && !$op->propertyHookCoalesceRead
            && $this->propertyReadUsesMagicGet($propertyObject, $name, $frame);
        // ?? / ??= use BP_VAR_IS: skip Error / Undefined from read visibility — isset-like (#29503).
        if (!$magicGetForRead && !$forWrite && !$op->propertyHookCoalesceRead) {
            $catchFrame = $this->enforcePropertyVisibilityRead($propertyObject, $name, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        if (!$magicGetForRead && !$forWrite && !$op->propertyHookCoalesceRead) {
            $invisibleParentPrivateMeta = $this->classPropertyMeta($propertyObject, $name, $frame);
            if (
                null !== $invisibleParentPrivateMeta
                && (
                    $invisibleParentPrivateMeta->phpInvisible
                    || $this->isParentPrivatePropertyInvisibleFromCaller(
                        $invisibleParentPrivateMeta,
                        $frame,
                        $propertyObject
                    )
                )
            ) {
                // Non-null receiver: nullsafe still warns like plain -> (#23705).
                $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                $this->context->errors->undefinedPropertyRead(
                    $propertyObject->class->name,
                    $name,
                    $this->context,
                    $frame,
                    $scriptFile
                );
                $result->null();
                return null;
            }
        }
        if ($op->propertyHookCoalesceRead && !$forWrite) {
            // ?? / ??= still throws on virtual write-only (zend BP_VAR_IS; #29240).
            $catchFrame = $this->enforceWriteOnlyVirtualPropertyRead($propertyObject, $name, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $catchFrame = $this->fetchObjectPropertyForCoalesce($propertyObject, $name, $result, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            return null;
        }
        if ($propertyObject->hasProperty($name) && !$magicGetForRead) {
            if (!$forWrite) {
                VM\LazyPropertySupport::ensureDeclarativeLazyPropertyInitialized(
                    $this,
                    $propertyObject,
                    $name
                );
            }
            if (!$forWrite) {
                $this->emitInstancePropertyAccessDeprecation($propertyObject, $name, $frame);
            }
            if ($forWrite) {
                // `$r = &$obj->inaccessible` / `return $obj->inaccessible` from `&fn`
                // — get_property_ptr_ptr fails; BP_VAR_W read_property invokes __get
                // (zend_object_handlers.c, #25688 / #29456).
                if (
                    $this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)
                    && $this->propertyReadUsesMagicGet($propertyObject, $name, $frame)
                ) {
                    $this->deliverInaccessiblePropertyFetchByRef(
                        $result,
                        $propertyObject,
                        $name,
                        $frame
                    );
                    return null;
                }
                $writeProxy = new Variable();
                $writeProxy->objectPropertyOwner = $propertyObject;
                $writeProxy->objectPropertyName = $name;
                // `$r = &$obj->readonlyProp` / by-ref return — zend_readonly.c (#25620 / #29456).
                // Must Error before binding; write-through checks alone leave REF_OK.
                if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                    $catchFrame = $this->enforceReadonlyPropertyFetchByRef($writeProxy, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                }
                // `$r = &$obj->hooked` / by-ref return — PROPERTY_FETCH_WRITE; Zend rejects
                // without `&get` at get_ptr time, not as write-only (#22475 / #29456).
                if (!$this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                    $catchFrame = $this->enforceVirtualPropertyHookWrite($writeProxy, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                }
                $readBeforeAssign = $this->propertyFetchDestUsedAsReadBeforeAssign($frame, $op);
                if ($readBeforeAssign) {
                    $hookValue = $this->fetchPropertyWithHooks($propertyObject, $name, $frame);
                    if (null !== $hookValue) {
                        $result->copyFrom($hookValue);
                        $result->objectPropertyOwner = $propertyObject;
                        $result->objectPropertyName = $name;
                        return null;
                    }
                }
                if ($this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)) {
                    $proxy = new Variable();
                    $proxy->objectPropertyOwner = $propertyObject;
                    $proxy->objectPropertyName = $name;
                    $catchFrame = $this->enforceAsymmetricPropertyWrite($proxy, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $this->tagReadonlyPropertyDimWriteContainer($result, $propertyObject, $name);
                    // `&get`-only: dim writes mutate live backing through the by-ref get (#21098).
                    if ($this->deliverByRefGetHookedPropertyDimWriteContainer(
                        $result,
                        $propertyObject,
                        $name,
                        $frame
                    )) {
                        return null;
                    }
                    // Without `&get`, refuse before RMW / backing write (#28590, php-src 8.4.24+).
                    $catchFrame = $this->enforceHookedPropertyDimWriteRequiresByRefGet(
                        $propertyObject,
                        $name,
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    // Virtual `&get`+`set`: RMW via get then set write-back (#21098).
                    $hookValue = $this->fetchPropertyWithHooks($propertyObject, $name, $frame);
                    if (null !== $hookValue) {
                        $catchFrame = $this->deliverHookedPropertyDimWriteContainer(
                            $result,
                            $hookValue,
                            $propertyObject,
                            $name,
                            $frame
                        );
                        if (null !== $catchFrame) {
                            return $catchFrame;
                        }
                        return null;
                    }
                }
                $catchFrame = $this->enforceDomDocumentReadOnlyPropertyWrite($propertyObject, $name, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $catchFrame = $this->enforceInternalDynamicPropertyCreate($propertyObject, $name, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                // Declared-but-UNDEF (e.g. after unset): BP_VAR_RW ++/-- warns like a read (#29241).
                // Magic __get supplies the value — no Undefined property (#31992, zend_object_handlers.c).
                $warnUndefAfterRw = $this->propertyFetchDestUsedAsIncDec($frame, $op)
                    && $this->objectPropertySlotIsUndefinedForRwWarn($propertyObject, $name, $frame)
                    && !$this->propertyReadUsesMagicGet($propertyObject, $name, $frame);
                $writeLvalue = $this->fetchObjectPropertyWriteLvalue($propertyObject, $name, $frame, $op);
                if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                    VM\TypedPropertyCheck::prepareWritableByReference($writeLvalue);
                }
                $result->indirect($writeLvalue);
                if ($warnUndefAfterRw) {
                    $this->warnUndefinedPropertyAfterIncDecRwFetch($propertyObject, $name, $frame);
                }
                if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                    $result->propertyRefAcquisition = true;
                } else {
                    $result->propertyAssignLvalue = true;
                }
                return null;
            }
            $catchFrame = $this->enforceWriteOnlyVirtualPropertyRead($propertyObject, $name, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $hookValue = $this->fetchPropertyWithHooks($propertyObject, $name, $frame);
            if (null !== $hookValue) {
                if ($this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)) {
                    if ($this->deliverByRefGetHookedPropertyDimWriteContainer(
                        $result,
                        $propertyObject,
                        $name,
                        $frame
                    )) {
                        return null;
                    }
                    $catchFrame = $this->enforceHookedPropertyDimWriteRequiresByRefGet(
                        $propertyObject,
                        $name,
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $catchFrame = $this->deliverHookedPropertyDimWriteContainer(
                        $result,
                        $hookValue,
                        $propertyObject,
                        $name,
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                } elseif ($this->propertyFetchDestUsedAsByRefForeachIterable($frame, $op)) {
                    // foreach ($obj->hooked as &$v) — FE_RESET_RW / #29215.
                    if ($this->deliverByRefGetHookedPropertyDimWriteContainer(
                        $result,
                        $propertyObject,
                        $name,
                        $frame
                    )) {
                        return null;
                    }
                    $catchFrame = $this->enforceHookedPropertyDimWriteRequiresByRefGet(
                        $propertyObject,
                        $name,
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $result->copyFrom($hookValue);
                } else {
                    $result->copyFrom($hookValue);
                }
            } else {
                if ($this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)) {
                    $proxy = new Variable();
                    $proxy->objectPropertyOwner = $propertyObject;
                    $proxy->objectPropertyName = $name;
                    $catchFrame = $this->enforceAsymmetricPropertyWrite($proxy, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $this->tagReadonlyPropertyDimWriteContainer($result, $propertyObject, $name);
                }
                $catchFrame = $this->enforceVirtualPropertyHookRawAccess(
                    $propertyObject,
                    $name,
                    true,
                    $frame
                );
                if (null !== $this->context->propertyHookExternalCatchFrame) {
                    return self::FAILURE;
                }
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $propMeta = $this->classPropertyMeta($propertyObject, $name, $frame);
                $domStaleMsg = VM\DomVmRuntimeSupport::fetchableNodeErrorMessage($propertyObject);
                if (null !== $domStaleMsg) {
                    $catchFrame = $this->dispatchVmError($domStaleMsg, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $result->null();
                    return null;
                }
                $propSlot = null !== $propMeta && $propertyObject->hasPropertyForMeta($propMeta)
                    ? $propertyObject->getPropertyForMeta($propMeta)
                    : $propertyObject->getProperty($name);
                if (
                    $op->nullsafeFetchPropertyRead
                    && $op->nullsafeUninitNullableToNull
                    && VM\TypedPropertyCheck::isUninitialized($propSlot)
                    && VM\TypedPropertyCheck::propertyAllowsNull($propSlot)
                ) {
                    $result->null();
                    return null;
                }
                // Untyped declared property after unset: E_WARNING + NULL (#22021, zend_object_handlers.c).
                // Nullsafe on a live object still warns (#23705) — only null receivers short-circuit.
                if (
                    $propSlot->resolveIndirect()->isUndefined()
                    && !VM\TypedPropertyCheck::isUninitialized($propSlot)
                ) {
                    $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                    $this->context->errors->undefinedPropertyRead(
                        $propertyObject->class->name,
                        $name,
                        $this->context,
                        $frame,
                        $scriptFile
                    );
                    $result->null();
                    return null;
                }
                // Dim-write (`$o->a[0]=` / `$o->a[]=`) is BP_VAR_W: uninitialized typed
                // array slots auto-init to []; other types TypeError (zend_try_array_init,
                // #31770 / #31819). Dim RW (`$o->a[0]++` / `+=`) is BP_VAR_RW Error (#31784).
                // foreach ($o->a as &$v) is FE_RESET_RW / get_property_ptr_ptr — same by-ref
                // uninitialized Error as `$r = &$o->a` (#31836), not the bare-read wording.
                if ($this->propertyFetchAllowsTypedArrayDimAutoInit($frame, $op)) {
                    VM\TypedPropertyCheck::tryInitEmptyArrayForDimWrite($propSlot);
                } elseif ($this->propertyFetchDestUsedAsByRefForeachIterable($frame, $op)) {
                    VM\TypedPropertyCheck::prepareWritableByReference($propSlot);
                } else {
                    VM\TypedPropertyCheck::assertReadable($propSlot);
                }
                // `$obj->arr[]=` / unset($obj->arr[$k]) need a live alias into property storage.
                // Plain R-mode fetches must copy: an indirect alias makes ternary/`&&` phi self-ASSIGN
                // look like a property write (readonly / DOM read-only / skipped `__get`) (#23986, #24250).
                // By-ref `return $this->prop` also needs the live cell (#29456) — compiler prefers
                // PROPERTY_FETCH_WRITE, but keep R-mode resilient when usages are empty.
                if (
                    $this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)
                    || $this->propertyFetchDestUsedAsReturnByRef($frame, $op)
                ) {
                    $result->indirect($propSlot);
                } elseif ($this->propertyFetchDestUsedAsByRefForeachIterable($frame, $op)) {
                    // Hooked array without &get must Error before FE_RESET_RW (#29215).
                    if ($this->deliverByRefGetHookedPropertyDimWriteContainer(
                        $result,
                        $propertyObject,
                        $name,
                        $frame
                    )) {
                        return null;
                    }
                    $catchFrame = $this->enforceHookedPropertyDimWriteRequiresByRefGet(
                        $propertyObject,
                        $name,
                        $frame
                    );
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    // Live HT for FE_RESET_RW (nullable uninit was null-inited above).
                    $result->indirect($propSlot);
                } else {
                    $result->copyFrom($propSlot);
                }
            }
            return null;
        }
        if ($forWrite) {
            // Missing / uninitialized declared prop still trips by-ref readonly (#25620).
            if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                $missingRefProxy = new Variable();
                $missingRefProxy->objectPropertyOwner = $propertyObject;
                $missingRefProxy->objectPropertyName = $name;
                $catchFrame = $this->enforceReadonlyPropertyFetchByRef($missingRefProxy, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }
            $catchFrame = $this->enforceReadonlyDynamicPropertyCreate($propertyObject, $name, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $catchFrame = $this->enforceInternalDynamicPropertyCreate($propertyObject, $name, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            // Missing dynamic prop: create then Undefined property for ++/-- (BP_VAR_RW, #29241).
            // Magic __get supplies the value — no Undefined property (#31992, zend_object_handlers.c).
            $warnUndefAfterRw = $this->propertyFetchDestUsedAsIncDec($frame, $op)
                && !$this->propertyReadUsesMagicGet($propertyObject, $name, $frame);
            $writeLvalue = $this->fetchObjectPropertyWriteLvalue($propertyObject, $name, $frame, $op);
            if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                VM\TypedPropertyCheck::prepareWritableByReference($writeLvalue);
            }
            $result->indirect($writeLvalue);
            if ($warnUndefAfterRw) {
                $this->warnUndefinedPropertyAfterIncDecRwFetch($propertyObject, $name, $frame);
            }
            if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                $result->propertyRefAcquisition = true;
            } else {
                $result->propertyAssignLvalue = true;
            }
            return null;
        }
        if ($magicGetForRead) {
            $this->deliverMagicGetRead($result, $propertyObject, $name);
            return null;
        }
        if (VM\SplArraySupport::hasArrayAsProps($propertyObject)) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($name);
            // php-src spl_array_read_property — Undefined array key (not property) (#28820).
            $result->copyFrom(VM\SplArraySupport::offsetGet($propertyObject, $key, $frame));
            return null;
        }
        // Undefined property on a non-null object: warn for both -> and ?-> (#23705).
        // Nullsafe only skips the warning when the receiver itself is null (TYPE_NULLSAFE).
        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
        $this->context->errors->undefinedPropertyRead(
            $propertyObject->class->name,
            $name,
            $this->context,
            $frame,
            $scriptFile
        );
        $result->null();
        return null;
    }
}
