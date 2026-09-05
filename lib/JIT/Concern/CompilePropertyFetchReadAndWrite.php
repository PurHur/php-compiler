<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Property fetch / fetch-write opcode lowering for JIT/AOT (#36387).
 *
 * Extracted from {@see CompileBlockInternal}: {@code TYPE_PROPERTY_FETCH} and
 * {@code TYPE_PROPERTY_FETCH_WRITE} so the monolith switch shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_object_handlers.c (zend_std_read_property / write_property),
 * Zend/zend_API.c (object property fetch), Zend/zend_execute.c (ZEND_FETCH_OBJ_*)
 * — move-only Concern extract; no new C ABI and no opcode/IR shape change.
 */
trait CompilePropertyFetchReadAndWrite
{
    private function compilePropertyFetchOp(
        Block $block,
        OpCode $op,
        int $i,
        PHPLLVM\Value $func,
        PHPLLVM\BasicBlock $basicBlock
    ): void {
        \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'prop_fetch_cont');
        $result = $block->getOperand($op->arg1);
        // Vacant result slot (dead fetch / relocated temp) — skip rather than
        // TypeError in hasVariableOp / UnboundThisGuard (#36382 Slim).
        if (null === $result) {
            return;
        }
        // Prior ??= fetch may leave a branch-local objectPropertySlot on a reused
        // CFG temp; drop it before this fetch so later loads dominate (#33760).
        if ($this->context->hasVariableOp($result)) {
            $stale = $this->context->getVariableFromOp($result);
            $stale->objectPropertySlot = null;
            $stale->objectPropertyType = null;
            $stale->objectPropertyReceiver = null;
            $stale->objectPropertyName = null;
            $stale->objectPropertyClassName = null;
            $stale->objectPropertyDnfArms = null;
        }
        $obj = $block->getOperand($op->arg2);
        if (\PHPCompiler\JIT\UnboundThisGuard::emitPropertyAccessIfUnbound($this->context, $this, $block, $obj, $result)) {
            return;
        }
        $name = $block->getOperand($op->arg3);
        $nameSlot = $op->arg3;
        // inheritUndefinedLocals freshLiteralConstantSlot can leave the first
        // AssignOp fetch's name slot vacant if the Literal was relocated (#34426).
        if (!$name instanceof Operand\Literal && null !== $nameSlot && isset($block->constants[$nameSlot])) {
            $name = new Operand\Literal($block->constants[$nameSlot]->toString());
        }
        $propName = $name instanceof Operand\Literal ? $name->value : null;
        // NestedJIT \PHPCompiler\VM\Variable is a __value__* box (#16565) — `$v->type` must
        // read the value-box type byte (masked), not an object property (#21921).
        if (
            \PHPCompiler\JIT\NestedJitCompileScope::isActive()
            && 'type' === $propName
            && $this->context->hasVariableOp($obj)
        ) {
            $recv = $this->context->getVariableFromOp($obj);
            if (Variable::TYPE_VALUE === $recv->type) {
                $valuePtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $recv);
                $map = $this->context->structFieldMap['__value__'];
                $i8 = $this->context->getTypeFromString('int8');
                $i64 = $this->context->getTypeFromString('int64');
                $typeByte = $this->context->builder->load(
                    $this->context->builder->structGep($valuePtr, $map['type'])
                );
                $kind = $this->context->builder->and(
                    $typeByte,
                    $i8->constInt(0x7f, false)
                );
                $this->assignOperandValue(
                    $result,
                    $this->context->builder->zExt($kind, $i64)
                );
                return;
            }
        }
        $nonObjectLabel = Variable::propertyFetchNonObjectTypeLabel(
            Variable::getTypeFromType($obj->type)
        );
        // zend_zval_value_name — constant bool prints true/false (#30054 / #30066).
        if ('bool' === $nonObjectLabel) {
            if ($obj instanceof Operand\Literal && \is_bool($obj->value)) {
                $nonObjectLabel = $obj->value ? 'true' : 'false';
            } elseif ($this->context->hasVariableOp($obj)) {
                $recvLabel = \PHPCompiler\JIT\JitOperandTypeLabel::givenLabel(
                    $this->context,
                    $this->context->getVariableFromOp($obj)
                );
                if ('true' === $recvLabel || 'false' === $recvLabel) {
                    $nonObjectLabel = $recvLabel;
                }
            }
        }
        // XMLReader::XML() result is CFG-typed bool (InternalArgInfo) but the JIT
        // variable is a live VALUE box tagged classUserType=XMLReader (#28670).
        if (
            null !== $nonObjectLabel
            && $this->context->hasVariableOp($obj)
            && 'XMLReader' === ($this->context->getVariableFromOp($obj)->classUserType ?? '')
        ) {
            $nonObjectLabel = null;
        }
        // DOMElement::removeAttributeNode() — same InternalArgInfo bool lie (#32707).
        if (
            null !== $nonObjectLabel
            && $this->context->hasVariableOp($obj)
            && \in_array(
                $this->context->getVariableFromOp($obj)->classUserType ?? '',
                ['DOMAttr', 'Dom\\Attr'],
                true
            )
        ) {
            $nonObjectLabel = null;
        }
        // `$c = new C` after an earlier ?-> on the same CV: CFG userType stays
        // generic/nullable while classUserType on the binding names the runtime class (#32749).
        if ($this->context->hasVariableOp($obj)) {
            $recvTagged = $this->context->getVariableFromOp($obj)->classUserType ?? null;
            if (
                is_string($recvTagged)
                && '' !== $recvTagged
                && !\in_array(strtolower(ltrim($recvTagged, '\\')), ['object', 'stdclass'], true)
            ) {
                $nonObjectLabel = null;
                $obj->type = new Type(Type::TYPE_OBJECT, [], $recvTagged);
            }
        }
        // Nested ?->: prior nullsafe result Temporaries are often typed TYPE_NULL even
        // though the fetch arm runs only after a runtime non-null check and the JIT
        // receiver is a VALUE/OBJECT box (#26818).
        $nullsafeRuntimeObjectReceiver = false;
        if (
            $op->nullsafeFetchPropertyRead
            && null !== $propName
            && (
                'null' === $nonObjectLabel
                || $op->nullsafeUninitNullableToNull
            )
            && $this->context->hasVariableOpInScopes($obj)
        ) {
            $nullsafeRecvVar = $this->context->getVariableFromOpInScopes($obj);
            // Ignore isNullConstant: the nullsafe null arm may set it on the shared
            // merge slot before the fetch arm is compiled (#26818).
            $recvClassUserType = $nullsafeRecvVar->classUserType ?? '';
            $nullsafeConcreteClass = is_string($recvClassUserType)
                && '' !== $recvClassUserType
                && !\in_array(strtolower(ltrim($recvClassUserType, '\\')), ['object', 'stdclass'], true);
            if (!$nullsafeConcreteClass) {
                $nullsafeRuntimeObjectReceiver = \in_array(
                    $nullsafeRecvVar->type,
                    [Variable::TYPE_VALUE, Variable::TYPE_OBJECT],
                    true
                );
            }
        }
        if (null !== $nonObjectLabel && null !== $propName && !$nullsafeRuntimeObjectReceiver) {
            $destSlot = (int) $op->arg1;
            $forWrite = $this->varFetchDestUsedAsAssignLvalue($block, $i, $destSlot)
                || $this->varFetchDestUsedAsCompoundAssignRead($block, $i, $destSlot);
            if ($forWrite) {
                // ZEND_PRE/POST_INC/DEC_OBJ: increment/decrement for any non-object
                // receiver (null, true, false, …) (#7431 / #30075).
                if ($this->varFetchDestUsedAsIncDec($block, $i, (int) $op->arg1)) {
                    $message = sprintf(
                        'Attempt to increment/decrement property "%s" on %s',
                        $propName,
                        $nonObjectLabel
                    );
                } else {
                    $message = sprintf(
                        'Attempt to assign property "%s" on %s',
                        $propName,
                        $nonObjectLabel
                    );
                }
                if ([] !== $this->context->tryCatch->handlerStack) {
                    \PHPCompiler\JIT\NonObjectPropertyFetchHelper::lowerNullPropertyDest($this->context, $result);
                    \PHPCompiler\JIT\TryCatchHelper::emitCatchableErrorMessage($this->context, $this, $message);
                } else {
                    \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise($this->context, $message);
                    $this->context->builder->call($this->context->lookupFunction('abort'));
                    $this->context->builder->clearInsertionPosition();
                }
                return;
            }
            if ($op->propertyHookCoalesceRead) {
                // ?? / ??= BP_VAR_IS on non-object — silent null (#30120, zend_vm_def.h).
                \PHPCompiler\JIT\NonObjectPropertyFetchHelper::lowerNullPropertyDest($this->context, $result);
                return;
            }
            if ($op->nullsafeFetchPropertyRead) {
                // IS-mode (??/isset/empty) or null: silent (#18026).
                // R-mode nullsafe on scalar: warn like plain -> (#26365).
                if ($op->nullsafeUninitNullableToNull || 'null' === $nonObjectLabel) {
                    \PHPCompiler\JIT\NonObjectPropertyFetchHelper::lowerNullPropertyDest($this->context, $result);
                    return;
                }
            } elseif ('null' === $nonObjectLabel) {
                $message = sprintf('Attempt to read property "%s" on null', $propName);
                if ([] !== $this->context->tryCatch->handlerStack) {
                    \PHPCompiler\JIT\NonObjectPropertyFetchHelper::lowerNullPropertyDest($this->context, $result);
                    \PHPCompiler\JIT\TryCatchHelper::emitCatchableErrorMessage($this->context, $this, $message);
                } else {
                    \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise($this->context, $message);
                    $this->context->builder->call($this->context->lookupFunction('abort'));
                    $this->context->builder->clearInsertionPosition();
                }
                return;
            }
            \PHPCompiler\JIT\NonObjectPropertyFetchHelper::lowerNonObjectPropertyRead(
                $this->context,
                $result,
                $propName,
                $nonObjectLabel
            );
            return;
        }
        if (!$nullsafeRuntimeObjectReceiver) {
            $recvIsValueBoxed = false;
            if ($this->context->hasVariableOpInScopes($obj)) {
                $recvIsValueBoxed = Variable::TYPE_VALUE
                    === $this->context->getVariableFromOpInScopes($obj)->type;
            }
            // Untyped / mixed formals are TYPE_VALUE boxes; CFG may say UNION/mixed
            // rather than TYPE_OBJECT (#34721).
            if (!$recvIsValueBoxed) {
                assert(null !== $obj->type && $obj->type->type === Type::TYPE_OBJECT);
            }
        }
        $declaringClass = $this->resolvePropertyDeclaringClass($obj, $block, $propName);
        // Lost userType after $c = $obj->prop / nested ?-> yields generic "object".
        // Prefer stdClass when that layout owns the property (object casts) (#26818).
        // PROPERTY_FETCH_WRITE (by-ref return / SEND_REF) must NOT rewrite to stdClass —
        // the real ClassEntry is only known at runtime for untyped/mixed `$o` (#34721).
        $opcodeIsPropertyWrite = OpCode::TYPE_PROPERTY_FETCH_WRITE === $op->type;
        $forWritePreview = $opcodeIsPropertyWrite
            || $this->varFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1);
        if (
            'object' === strtolower(ltrim($declaringClass, '\\'))
            && !$opcodeIsPropertyWrite
        ) {
            $stdClassId = $this->context->type->object->lookup('stdClass');
            $nullsafeEnumPseudoProp = $nullsafeRuntimeObjectReceiver
                && null !== $propName
                && \in_array($propName, ['name', 'value'], true)
                && [] !== $this->context->type->object->registeredEnumClassIds();
            if (
                $forWritePreview
                || (
                    null !== $propName
                    && $this->context->type->object->hasProperty($stdClassId, $propName)
                )
                || $nullsafeEnumPseudoProp
            ) {
                $declaringClass = 'stdClass';
            }
        }
        // User-script AOT: documentElement / firstChild temps often lose DOMElement
        // userType (#23251). nodeName/tagName on stdClass define a new slot and
        // SIGSEGV after setAttribute (DOMAttr shares the name).
        if (
            null !== $propName
            && \in_array(
                strtolower($propName),
                ['textcontent', 'nodevalue', 'nodename', 'tagname'],
                true
            )
            && \in_array(strtolower($declaringClass), ['object', 'stdclass', ''], true)
            && (
                $this->context->extensionLowering->domCompileTime?->lastLoadWasPureUserScript()
                || null !== $this->context->extensionLowering->domCompileTime?->lastFetchedTagName()
            )
        ) {
            $declaringClass = 'DOMElement';
        }
        // Living Dom\Attr orphans lose static type; `$o->value` collides with
        // SensitiveParameterValue::$value on generic `object` (#21083 / #27108).
        if (
            null !== $propName
            && 'value' === strtolower($propName)
            && $this->context->hasVariableOp($obj)
        ) {
            $recvVar = $this->context->getVariableFromOp($obj);
            $attrLocal = $recvVar->compileTimeDomAttrLocalName ?? null;
            $attrClass = $recvVar->classUserType ?? null;
            if (
                null !== $attrLocal
                || (is_string($attrClass) && str_starts_with($attrClass, 'Dom\\'))
            ) {
                $declaringClass = 'Dom\\Attr';
            }
        }
        $receiver = $this->loadPropertyFetchReceiver($obj);
        $phiDest = $this->ternaryEchoPhiPropertyFetchDest($block, $i);
        if (null !== $phiDest) {
            $result = $phiDest;
        }
        // Nullsafe fetch must copy into the ?-> merge alloca — rebinding the result to
        // a Variable that still carries objectPropertySlot leaves a fetch-arm-only
        // SSA pointer that does not dominate nullsafe_merge (#32988).
        if (
            $op->nullsafeFetchPropertyRead
            && !$this->context->coalesceAssignTargets->contains($result)
        ) {
            $this->context->coalesceAssignTargets[$result] = true;
        }
        $forceBranchMerge = $this->context->coalesceAssignTargets->contains($result);
        if ($forceBranchMerge) {
            $this->ensureCoalesceMergeStackSlot($result);
            if (!$this->context->hasVariableOp($result)) {
                $this->context->makeVariableFromOp($func, $basicBlock, $block, $result);
            }
            $mergeVar = $this->context->getVariableFromOp($result);
            if (Variable::KIND_VALUE === $mergeVar->kind) {
                $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_VALUE,
                        Variable::KIND_VARIABLE,
                        $slot
                    )
                );
            }
        }
        // Honor CFG PROPERTY_FETCH_WRITE even when the next op is TYPE_RETURN
        // (by-ref return) — varFetchDestUsedAsAssignLvalue only sees ASSIGN (#34721).
        $forWrite = $opcodeIsPropertyWrite
            || $this->varFetchDestUsedAsAssignLvalue($block, $i, (int) $op->arg1);
        $forDimWrite = $this->varFetchDestUsedAsDimWriteContainer($block, $i, (int) $op->arg1);
        // `$o->p[$k]` / `$r =& $o->p[$k]`: keep the live property HT (FETCH_OBJ_W),
        // do not reseat into a value-box copy — a second fetch would COW-separate and
        // detach earlier refs (#35980 / leftover #34673; zend_std_get_property_ptr_ptr).
        $propFetchForWrite = $forWrite
            || $forDimWrite
            || $this->varFetchDestUsedAsPlainAssignStore($block, $i, (int) $op->arg1);
        $bindPropAsWrite = $forWrite || $forDimWrite;
        if ($name instanceof Operand\Literal) {
            // PHPCfg types `new static()` receivers as static — skip declaring-class
            // guards that target a bogus "static" ClassEntry (#31937).
            // Also: unserialize() runtime O: results lack classUserType — use
            // __object__.class_id (#34602 file-backed DateInterval residual).
            // Untyped/mixed `$o` keeps CFG userType "object" — resolve via class_id
            // so by-ref return aliases the real heap slot (#34721 / re-#34717).
            // Also TYPE_PROPERTY_FETCH used as ASSIGN lvalue (`$o->x = …`): CFG often
            // omits PROPERTY_FETCH_WRITE, and a compile-time stdClass/object layout
            // writes a different slot than Body::$x — callee sees the store, caller
            // does not (#36386 nbody / array-elem property stores).
            // php-src: Zend/zend_object_handlers.c zend_get_property_offset (ce from
            // Z_OBJCE_P, not a static "object" ClassEntry).
            $declLcForRuntime = strtolower(ltrim($declaringClass, '\\'));
            if (
                'static' === $declLcForRuntime
                || $this->receiverIsFromUnserializeObject($obj)
                || (
                    $propFetchForWrite
                    && \in_array($declLcForRuntime, ['object', 'stdclass', 'mixed', ''], true)
                )
            ) {
                \PHPCompiler\JIT\LazyObjectHelper::emitEnsureInitialized(
                    $this->context,
                    $this->loadPropertyFetchReceiver($obj)
                );
                // Prefer an in-TU declared owner via __object__.class_id (#36386 / #36532).
                // When none exists (SPINE_CHUNK partial TU — ClassEntry not in the chunk),
                // fall through to object/stdClass dynamic defineProperty instead of aborting
                // ("Property isInternal not found…") — restores ext/ds chunk emit (#36387).
                // php-src: Zend/zend_object_handlers.c zend_std_write_property.
                $fetched = $this->context->type->object->tryPropertyFetchByRuntimeReceiverClass(
                    $receiver,
                    $name->value,
                    $propFetchForWrite
                );
                if (null === $fetched) {
                    if (
                        'static' === $declLcForRuntime
                        || $this->receiverIsFromUnserializeObject($obj)
                    ) {
                        throw new \LogicException(
                            'PROPERTY_FETCH_WRITE could not resolve runtime class for property '
                            .$name->value
                        );
                    }
                    // object/stdclass/mixed write: continue to ordinary path below.
                } else {
                    $this->stampPropertyFetchReceiverOp($fetched, $obj);
                    \PHPCompiler\JIT\BasicBlockHelper::repositionToLastOpenIfInsertLost($this->context);
                    if ($forDimWrite) {
                        if ($this->varFetchDestUsedAsDimRwContainer($block, $i, (int) $op->arg1)) {
                            \PHPCompiler\JIT\TypedPropertyUninitGuard::emitBeforeRead($this->context, $fetched);
                        } else {
                            \PHPCompiler\JIT\TypedPropertyUninitGuard::emitBeforeDimWrite($this->context, $fetched);
                        }
                    }
                    if ($forceBranchMerge) {
                        $this->assignOperand($result, $fetched, true);
                    } else {
                        $this->bindPropertyFetchResult($result, $fetched, $bindPropAsWrite);
                    }
                    return;
                }
            }
            $classId = $this->context->type->object->lookup($declaringClass);
            // SimpleXMLElement FETCH_OBJ_W: host-fold at ASSIGN via tryPropSet (#35820).
            // propertyStore on a TYPE_VALUE SXE box SIGABRTs (leftover of #35814).
            if (
                $forWrite
                && \PHPCompiler\JIT\UserScriptAotEnv::isActive()
            ) {
                $sxeWriteLc = strtolower(ltrim($declaringClass, '\\'));
                if (
                    'simplexmlelement' === $sxeWriteLc
                    || 'simplemxml_element' === $sxeWriteLc
                ) {
                    $sxeReceiver = $this->context->getVariableFromOp($obj);
                    $lvalue = new Variable(
                        $this->context,
                        Variable::TYPE_NULL,
                        Variable::KIND_VALUE,
                        $this->context->getTypeFromString('__value__*')->constNull()
                    );
                    $lvalue->magicSetReceiver = $receiver;
                    $lvalue->magicSetName = $name->value;
                    $lvalue->objectPropertyClassName = $declaringClass;
                    $lvalue->compileTimeString = $sxeReceiver->compileTimeString;
                    if ($forceBranchMerge) {
                        $this->assignOperand($result, $lvalue, true);
                    } else {
                        $this->context->scope->variables[$result] = $lvalue;
                    }
                    return;
                }
            }
            // Static via -> / ?->: visibility Error for inaccessible statics (#30017).
            // Notice is VM-complete; JIT mid-body Notice SEGVs under MCJIT (pre-existing).
            $staticAsInstance = \PHPCompiler\JIT\StaticPropertyAsNonStaticJitGuard::emitBeforeInstanceFetch(
                $this->context->type->object,
                $this,
                $block,
                $classId,
                $declaringClass,
                $name->value,
                (bool) $op->propertyHookCoalesceRead
            );
            if (\PHPCompiler\JIT\StaticPropertyAsNonStaticJitGuard::CONTINUE !== $staticAsInstance) {
                return;
            }
            if (
                $forWrite
                && !$this->context->type->object->hasProperty($classId, $name->value)
                && \PHPCompiler\JIT\MagicMethodDispatch::hasInstanceMethod(
                    $this->context->type->object,
                    $classId,
                    '__set'
                )
            ) {
                $lvalue = new Variable(
                    $this->context,
                    Variable::TYPE_NULL,
                    Variable::KIND_VALUE,
                    $this->context->getTypeFromString('__value__*')->constNull()
                );
                $lvalue->magicSetReceiver = $receiver;
                $lvalue->magicSetName = $name->value;
                $lvalue->objectPropertyClassName = $declaringClass;
                if ($forceBranchMerge) {
                    $this->assignOperand($result, $lvalue, true);
                } else {
                    $this->context->scope->variables[$result] = $lvalue;
                }
                return;
            }
            if (
                $forWrite
                && !$this->context->type->object->hasProperty($classId, $name->value)
                && \PHPCompiler\JIT\MagicMethodDispatch::hasInstanceMethod(
                    $this->context->type->object,
                    $classId,
                    '__get'
                )
                && !\PHPCompiler\JIT\MagicMethodDispatch::hasInstanceMethod(
                    $this->context->type->object,
                    $classId,
                    '__set'
                )
                && $this->varFetchDestUsedAsIncDec($block, $i, (int) $op->arg1)
            ) {
                // Defer dynamic slot — ++/-- reads via __get (#32016, zend_object_handlers.c).
                $lvalue = new Variable(
                    $this->context,
                    Variable::TYPE_NULL,
                    Variable::KIND_VALUE,
                    $this->context->getTypeFromString('__value__*')->constNull()
                );
                $lvalue->magicSetReceiver = $receiver;
                $lvalue->magicSetName = $name->value;
                $lvalue->objectPropertyClassName = $declaringClass;
                if ($forceBranchMerge) {
                    $this->assignOperand($result, $lvalue, true);
                } else {
                    $this->context->scope->variables[$result] = $lvalue;
                }
                return;
            }
            if (
                $forWrite
                && !$this->context->type->object->hasProperty($classId, $name->value)
                && \PHPCompiler\VM\ArrayObjectJitHelper::isArrayAsPropsClass($declaringClass)
            ) {
                // ARRAY_AS_PROPS writes go to `__spl_ht` — not dynamic props (#33068).
                // Must run before rejectsDynamicProperties abort (external SPL classes).
                $recvVar = $this->context->getVariableFromOp($obj);
                if (Variable::TYPE_OBJECT === $recvVar->type) {
                    $receiver = $this->context->helper->loadValue($recvVar);
                } elseif (Variable::TYPE_VALUE === $recvVar->type) {
                    $receiver = $this->context->builder->call(
                        $this->context->lookupFunction('__value__readObject'),
                        \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $recvVar)
                    );
                } else {
                    $receiver = null;
                }
                $lvalue = null !== $receiver
                    ? \PHPCompiler\VM\ArrayObjectJitHelper::tryPropertyFetchWrite(
                        $this->context->type->object,
                        $receiver,
                        $declaringClass,
                        $name->value
                    )
                    : null;
                if (null !== $lvalue) {
                    if ($forceBranchMerge) {
                        $this->assignOperand($result, $lvalue, true);
                    } else {
                        $this->context->scope->variables[$result] = $lvalue;
                    }
                    return;
                }
            }
            if (
                $forWrite
                && !$this->context->type->object->hasProperty($classId, $name->value)
                && $this->context->type->object->isReadonlyClass($classId)
                && !\PHPCompiler\JIT\MagicMethodDispatch::hasInstanceMethod(
                    $this->context->type->object,
                    $classId,
                    '__set'
                )
            ) {
                \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise(
                    $this->context,
                    sprintf(
                        'Cannot create dynamic property %s::$%s',
                        $declaringClass,
                        $name->value
                    )
                );
                $this->context->builder->call($this->context->lookupFunction('abort'));
                $this->context->builder->clearInsertionPosition();
                return;
            }
            if (
                $forWrite
                && !$this->context->type->object->hasProperty($classId, $name->value)
                && $this->context->type->object->rejectsDynamicProperties($classId)
                && !\PHPCompiler\JIT\MagicMethodDispatch::hasInstanceMethod(
                    $this->context->type->object,
                    $classId,
                    '__set'
                )
            ) {
                // Enums / Closure / Fiber / … — catchable Error (#26588, #26371).
                $message = sprintf(
                    'Cannot create dynamic property %s::$%s',
                    $declaringClass,
                    $name->value
                );
                if ([] !== $this->context->tryCatch->handlerStack) {
                    \PHPCompiler\JIT\TryCatchHelper::emitCatchableErrorMessage($this->context, $this, $message);
                } else {
                    \PHPCompiler\JIT\Builtin\ErrorRaise::emitRaise($this->context, $message);
                    $this->context->builder->call($this->context->lookupFunction('abort'));
                    $this->context->builder->clearInsertionPosition();
                }
                return;
            }
            if (
                $forWrite
                && !$this->context->type->object->hasProperty($classId, $name->value)
            ) {
                $deprecationLine = null !== $op->sourceLocation && $op->sourceLocation->startLine > 0
                    ? $op->sourceLocation->startLine
                    : 0;
                \PHPCompiler\JIT\DynamicPropertyDeprecationGuard::emitBeforeUndeclaredWrite(
                    $this->context,
                    $this->context->type->object,
                    $classId,
                    $declaringClass,
                    $name->value,
                    $block->scriptPath(),
                    $deprecationLine
                );
                // AOT external-only abort clears the insert block — stop this opcode (#26818).
                if (null === \PHPCompiler\JIT\BasicBlockHelper::tryGetInsertBlock($this->context)) {
                    return;
                }
                // BP_VAR_RW (++/--): Undefined property after create (zend_object_handlers.c, #29241).
                // Magic __get supplies the value — no Undefined property (#31992).
                if (
                    $this->varFetchDestUsedAsIncDec($block, $i, (int) $op->arg1)
                    && !\PHPCompiler\JIT\MagicMethodDispatch::propertyReadUsesMagicGetAtCompileTime(
                        $this->context,
                        $classId,
                        $declaringClass,
                        $name->value,
                        $block
                    )
                ) {
                    \PHPCompiler\JIT\Builtin\UndefinedPropertyFetchRuntime::emitWarning(
                        $this->context,
                        $declaringClass,
                        $name->value
                    );
                }
            }
            if (!$forWrite) {
                // User-script AOT: SimpleXMLElement child views via host tree (#26863).
                // Magic __get is not always registered on Object_ for SXE; fold directly.
                if (\PHPCompiler\JIT\UserScriptAotEnv::isActive()) {
                    $declLc = strtolower(ltrim($declaringClass, '\\'));
                    if ('simplexmlelement' === $declLc || 'simplemxml_element' === $declLc) {
                        $sxeReceiver = $this->context->getVariableFromOp($obj);
                        $sxeName = \PHPCompiler\JIT\Variable::fromLiteral(
                            $this->context,
                            new Operand\Literal($name->value)
                        );
                        $sxeFetched = $this->context->extensionLowering->tryPropertyGet(
                            $this->context,
                            $sxeReceiver,
                            $sxeName
                        );
                        if (null !== $sxeFetched) {
                            $this->assignOperandValue($result, $sxeFetched);
                            $magicVar = $this->context->getVariableFromOp($result);
                            $magicVar->magicGetOverloadedName = $name->value;
                            // Bind host tree + baked name/text onto the property
                            // result — same as dim (#27438) — so (string)$sxe->child
                            // folds without NestedJIT / OOB cast (#28639).
                            $this->context->extensionLowering->applyPendingElementAssign(
                                $magicVar
                            );
                            // Stamp class like children()/asXML results (#35828). Without
                            // it, `$x->a->b` / `$x->a->b =` sees generic `object` and
                            // skips tryGet/tryPropSet (silent empty / no-op; #35834).
                            $this->stampSimpleXmlElementUserType($result, $magicVar);
                            return;
                        }
                    }
                }
                $magicFetched = \PHPCompiler\JIT\MagicMethodDispatch::tryEmitMagicGet(
                    $this->context,
                    $receiver,
                    $declaringClass,
                    $name->value,
                    $block
                );
                if (null !== $magicFetched) {
                    $this->assignOperandValue($result, $magicFetched);
                    $magicVar = $this->context->getVariableFromOp($result);
                    $magicVar->magicGetOverloadedClass = $declaringClass;
                    $magicVar->magicGetOverloadedName = $name->value;
                    return;
                }
            }
            if (!$forWrite) {
                // `$o->hooked[]=` / `$o->hooked[$k]=` without `&get` (#29748 / #28590).
                // Try-body `$o` often loses CFG userType → generic "object"; recover
                // the hooked declaring class before the guard (#29748).
                $hookClass = $declaringClass;
                if ($forDimWrite) {
                    $hookClass = $this->resolveHookedPropertyDeclaringClass(
                        $obj,
                        $declaringClass,
                        $name->value
                    );
                }
                if (
                    $forDimWrite
                    && \PHPCompiler\JIT\PropertyHookDispatch::emitDimWriteRequiresByRefGetGuard(
                        $this->context,
                        $this,
                        $receiver,
                        $hookClass,
                        $name->value,
                        $block
                    )
                ) {
                    return;
                }
                $hookFetched = \PHPCompiler\JIT\PropertyHookDispatch::tryEmitPropertyGet(
                    $this->context,
                    $receiver,
                    $declaringClass,
                    $name->value,
                    $block
                );
                if (null !== $hookFetched) {
                    $this->assignOperandValue($result, $hookFetched);
                    return;
                }
            }
            if (!$forWrite && \PHPCompiler\JIT\PropertyHookDispatch::emitWriteOnlyVirtualReadGuard(
                $this->context,
                $this,
                $declaringClass,
                $name->value
            )) {
                return;
            }
            if (!$forWrite && $op->propertyHookCoalesceRead) {
                // ?? / ??= BP_VAR_IS: inaccessible → silent null, not Error/Undefined (#29503).
                if (\PHPCompiler\JIT\InstancePropertyVisibilityJitGuard::trySilentNullForIsModeFetch(
                    $this->context->type->object,
                    $this,
                    $block,
                    $classId,
                    $name->value,
                    $declaringClass,
                    $result
                )) {
                    return;
                }
            } elseif (!$forWrite) {
                \PHPCompiler\JIT\InstancePropertyVisibilityJitGuard::emitBeforeFetch(
                    $this->context->type->object,
                    $this,
                    $block,
                    $classId,
                    $name->value,
                    $declaringClass
                );
            }
            if (
                !$forWrite
                && !$op->propertyHookCoalesceRead
                && \PHPCompiler\JIT\InstancePropertyVisibilityJitGuard::isInvisibleParentPrivateFetch(
                    $this->context->type->object,
                    $classId,
                    $name->value,
                    $block,
                    $this->resolvePropertyFetchReceiverClassName($obj, $block, $declaringClass)
                )
            ) {
                // Non-null receiver: nullsafe still warns like plain -> (#23705).
                \PHPCompiler\JIT\UndefinedPropertyFetchHelper::lowerUndefinedDynamicPropertyRead(
                    $this->context,
                    $result,
                    $this->resolvePropertyFetchReceiverClassName($obj, $block, $declaringClass),
                    $name->value
                );
                return;
            }
            if (
                !$forWrite
                && !$this->context->type->object->hasProperty($classId, $name->value)
                && $this->context->type->object->allowsDynamicProperties($classId)
            ) {
                // Generic "object"/stdClass receivers after ?-> + ?? must not warn
                // Undefined property: stdClass::$name when the runtime value is an
                // enum case — propertyFetch does class_id dispatch (#27666 / #26818).
                $propNameExact = $name->value;
                $skipDynamicUndef = (
                    ('name' === $propNameExact || 'value' === $propNameExact)
                    && [] !== $this->context->type->object->registeredEnumClassIds()
                );
                if (!$skipDynamicUndef) {
                    if ($op->propertyHookCoalesceRead) {
                        // BP_VAR_IS / ?? LHS: silent null (FETCH_OBJ_IS, #30030).
                        \PHPCompiler\JIT\NonObjectPropertyFetchHelper::lowerNullPropertyDest(
                            $this->context,
                            $result
                        );
                    } else {
                        // Non-null receiver: nullsafe still warns like plain -> (#23705).
                        \PHPCompiler\JIT\UndefinedPropertyFetchHelper::lowerUndefinedDynamicPropertyRead(
                            $this->context,
                            $result,
                            $declaringClass,
                            $name->value
                        );
                    }
                    return;
                }
            }
            \PHPCompiler\JIT\LazyObjectHelper::emitEnsureInitialized(
                $this->context,
                $this->loadPropertyFetchReceiver($obj)
            );
            $fetched = $this->context->type->object->propertyFetch(
                $receiver,
                $declaringClass,
                $name->value,
                $propFetchForWrite,
                $this->context->getVariableFromOp($obj)
            );
            $this->stampPropertyFetchReceiverOp($fetched, $obj);
            if ($this->context->hasVariableOp($obj)) {
                $recvVar = $this->context->getVariableFromOp($obj);
                if (null !== $recvVar->compileTimeDomImportHostSxeToken) {
                    $fetched->compileTimeDomImportHostSxeToken = $recvVar->compileTimeDomImportHostSxeToken;
                }
            }
            \PHPCompiler\JIT\BasicBlockHelper::repositionToLastOpenIfInsertLost($this->context);
            if ($forDimWrite) {
                // BP_VAR_W auto-init (#31770); BP_VAR_RW ++/+= must Error (#31784).
                if ($this->varFetchDestUsedAsDimRwContainer($block, $i, (int) $op->arg1)) {
                    \PHPCompiler\JIT\TypedPropertyUninitGuard::emitBeforeRead($this->context, $fetched);
                } else {
                    \PHPCompiler\JIT\TypedPropertyUninitGuard::emitBeforeDimWrite($this->context, $fetched);
                }
            } elseif (
                !$forWrite
                && !$op->propertyHookCoalesceRead
                && !$op->nullsafeFetchPropertyRead
                && !$this->propertyFetchResultUsedOnlyAsIsset($block, $i, (int) $op->arg1)
            ) {
                // BP_VAR_R: raise while fetch metadata + insert BB are live. Echo-time
                // guards can no-op when try/catch already sealed the BB (#33886).
                // Skip isset/?? precursors (TYPE_ISSET → COALESCE, #29688).
                \PHPCompiler\JIT\TypedPropertyUninitGuard::emitBeforeRead($this->context, $fetched);
            }
            if ($forceBranchMerge || $op->nullsafeFetchPropertyRead) {
                // Nullsafe merge must not retain fetch-arm objectPropertySlot (#32988).
                // ??= left is often TYPE_PROPERTY_FETCH (not _WRITE) but still an
                // assign-lvalue into the coalesce merge — keep the slot (#33748).
                $savedRetain = $this->context->retainCoalesceInstancePropertyLvalue;
                if (
                    $forceBranchMerge
                    && $forWrite
                    && !$op->nullsafeFetchPropertyRead
                ) {
                    $this->context->retainCoalesceInstancePropertyLvalue = true;
                }
                try {
                    $this->assignOperand($result, $fetched, true);
                } finally {
                    $this->context->retainCoalesceInstancePropertyLvalue = $savedRetain;
                }
            } else {
                $this->bindPropertyFetchResult($result, $fetched, $bindPropAsWrite);
            }
            if ($op->nullsafeFetchPropertyRead && $this->context->hasVariableOp($result)) {
                $bound = $this->context->getVariableFromOp($result);
                $bound->objectPropertySlot = null;
                $bound->objectPropertyType = null;
                $bound->objectPropertyReceiver = null;
                $bound->objectPropertyName = null;
                $bound->objectPropertyClassName = null;
                $bound->objectPropertyDnfArms = null;
                // Null arm may have stamped isNullConstant on this shared merge slot (#34024).
                $bound->isNullConstant = false;
            }
            $this->applyExternalPropertyResultType($result, $declaringClass, $name->value);
        } else {
            if (!$name instanceof Operand) {
                throw new \LogicException(
                    'PROPERTY_FETCH name operand missing at slot '.(string) $op->arg3
                );
            }
            $nameVar = $this->context->getVariableFromOp($name);
            $fetched = $this->context->type->object->propertyFetchDynamic(
                $receiver,
                $declaringClass,
                $nameVar
            );
            $this->stampPropertyFetchReceiverOp($fetched, $obj);
            if ($forceBranchMerge) {
                $this->assignOperand($result, $fetched, true);
            } else {
                $this->bindPropertyFetchResult($result, $fetched, $bindPropAsWrite);
            }
        }
    }
}
