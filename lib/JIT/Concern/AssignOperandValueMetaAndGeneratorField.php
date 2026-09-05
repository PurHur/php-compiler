<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Operand-value assign, compile-time metadata sync, and generator-field write (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code assignOperandValue}
 * through {@code assignValueToGeneratorField} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute.c assignment / value copy paths and generator
 * frame value stores (Zend/zend_generators.c) — move-only Concern extract;
 * no new C ABI and no opcode/IR shape change.
 */
trait AssignOperandValueMetaAndGeneratorField
{
    private function assignOperandValue(Operand $result, PHPLLVM\Value $value, bool $force = false): void {
        if (!$force && empty($result->usages) && !$this->context->scope->variables->contains($result)) {
            return;
        }
        if (!$this->context->hasVariableOp($result)) {
            $this->context->makeVariableFromValueOp($value, $result);

            return;
        }
        $dest = $this->context->getVariableFromOp($result);
        if ($dest->kind !== Variable::KIND_VARIABLE) {
            if ($dest->functionStaticGlobal) {
                $source = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $value
                );
                $this->assignOperand($result, $source);

                return;
            }
            $name = JIT\OperandName::resolve($result);
            if (null === $name || '' === $name) {
                $block = $this->context->jitEnclosingBlock;
                if (null !== $block) {
                    $slot = $block->slotForOperand($result);
                    if (null !== $slot) {
                        foreach ($block->scopedOperands() as $scopeOp) {
                            if ($block->slotForOperand($scopeOp) !== $slot) {
                                continue;
                            }
                            $scopeName = JIT\OperandName::resolve($scopeOp);
                            if (null !== $scopeName && '' !== $scopeName) {
                                $name = $scopeName;
                                break;
                            }
                        }
                    }
                }
            }
            if (null !== $name && '' !== $name && isset($this->context->jitImportedGlobalNames[$name])) {
                $source = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VALUE,
                    $value
                );
                $this->assignOperand($result, $source);

                return;
            }
            $valueTyEarly = $this->context->getStringFromType($value->typeOf());
            if (
                Variable::KIND_VALUE === $dest->kind
                && Variable::TYPE_STRING === $dest->type
                && ('int1' === $valueTyEarly || 'bool' === $valueTyEarly)
            ) {
                // && short-circuit / boolean-not can target a phi slot still typed string (#1492, #16828).
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_NATIVE_BOOL,
                        Variable::KIND_VALUE,
                        $value
                    )
                );

                return;
            }
            if (Variable::KIND_VALUE === $dest->kind) {
                // Folded spine-guard bindings and foreach/try phi temps (#16828).
                $this->context->makeVariableFromValueOp($value, $result);

                return;
            }
            throw new \LogicException('Cannot assignOperandValue to a value');
        }
        $valueTy = $this->context->getStringFromType($value->typeOf());
        $destTy = $this->context->getStringFromType($dest->value->typeOf());
        if (Variable::TYPE_NATIVE_BOOL === $dest->type) {
            if ('__value__' === $valueTy || '__value__*' === $valueTy) {
                // By-value `__value__` must be stored into an alloca first — pointer() on a
                // struct yields illegal addrspacecast %__value__ → %__value__* (#27346).
                $valuePtr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $value);
                $dest->free();
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $valuePtr);
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_VALUE,
                        Variable::KIND_VARIABLE,
                        $slot
                    )
                );

                return;
            }
            if ('int1' === $valueTy || 'bool' === $valueTy) {
                if (Variable::KIND_VALUE === $dest->kind) {
                    $dest->free();
                    $this->context->setVariableOp(
                        $result,
                        Variable::fromValueOp($this->context, $value, $result)
                    );

                    return;
                }
                $dest->free();
                $this->context->builder->store($value, $dest->value);
                $dest->addref();

                return;
            }
        }
        if (Variable::TYPE_NATIVE_LONG === $dest->type || Variable::TYPE_NATIVE_DOUBLE === $dest->type) {
            if ('__value__' === $valueTy || '__value__*' === $valueTy) {
                // Property-hook get returns by-value `__value__` into a typed int/float slot
                // (PROFILE=8.4); coerce rather than pointerCast the struct (#27346).
                $valuePtr = JIT\JitValueBox::coerceToValuePtrForStore($this->context, $value);
                $dest->free();
                $slot = JIT\JitValueBox::alloc($this->context);
                JIT\JitValueBox::copyFromPointer($this->context, $slot, $valuePtr);
                $this->context->setVariableOp(
                    $result,
                    new Variable(
                        $this->context,
                        Variable::TYPE_VALUE,
                        Variable::KIND_VARIABLE,
                        $slot
                    )
                );

                return;
            }
        }
        if ('__string__*' === $valueTy && Variable::TYPE_VALUE === $dest->type) {
            // Replace the value-box Variable with a typed string. The previous path wrote the
            // string into the existing box but set isNullConstant while emitting the null-ptr
            // IR arm — that PHP-side flag stuck even when the runtime takes the copy arm, so
            // UnhandledMatchError::__construct saw a "null" message after match helpers (#29747).
            $dest->free();
            unset($this->context->scope->variables[$result]);
            $this->context->setVariableOp(
                $result,
                new Variable(
                    $this->context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VALUE,
                    $value
                )
            );

            return;
        }
        if ('__object__*' === $valueTy && Variable::TYPE_VALUE === $dest->type) {
            $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $this->context->getTypeFromString('__object__*'));
            $this->context->builder->store($value, $slot);
            $var = new Variable(
                $this->context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VARIABLE,
                $slot
            );
            $var->addref();
            $this->context->setVariableOp($result, $var);
            $resolved = JIT\OperandName::resolve($result);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($resolved),
                    $var
                );
            }

            return;
        }
        if ('__value__*' === $valueTy && Variable::TYPE_VALUE === $dest->type) {
            $dest->free();
            $isNullPtr = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $value,
                $value->typeOf()->constNull()
            );
            $nullBlock = JIT\BasicBlockHelper::append($this->context, 'assign_value_null_ptr');
            $copyBlock = JIT\BasicBlockHelper::append($this->context, 'assign_value_copy_ptr');
            $doneBlock = JIT\BasicBlockHelper::append($this->context, 'assign_value_ptr_done');
            $this->context->builder->branchIf($isNullPtr, $nullBlock, $copyBlock);
            $this->context->builder->positionAtEnd($nullBlock);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeNull'),
                JIT\JitValueBox::pointer($this->context, $dest->value)
            );
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($copyBlock);
            JIT\JitValueBox::copyFromPointer($this->context, $dest->value, $value);
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($doneBlock);
            $dest->addref();

            return;
        }
        $source = new Variable(
            $this->context,
            $this->jitTypeFromLlvmValue($value),
            Variable::KIND_VALUE,
            $value
        );
        if ($source->type === $dest->type) {
            $dest->free();
            if (Variable::TYPE_VALUE === $dest->type && ('__value__' === $destTy || '__value__*' === $destTy)) {
                $destLlvm = $dest->value->typeOf();
                $destPointsAtStruct = '__value__' === $destTy;
                if (
                    '__value__*' === $destTy
                    && \PHPLLVM\Type::KIND_POINTER === $destLlvm->getKind()
                    && '__value__' === $this->context->getStringFromType($destLlvm->getElementType())
                ) {
                    $destPointsAtStruct = true;
                }
                if ('__value__' === $valueTy && $destPointsAtStruct) {
                    $this->context->builder->store($value, $dest->value);
                    $dest->addref();
                    $this->copyValueBoxJitFlags($dest, $source);

                    return;
                }
                $ptr = '__value__*' === $valueTy
                    ? $value
                    : $this->valueBoxPointer($source);
                if ($destPointsAtStruct) {
                    JIT\JitValueBox::copyFromPointer($this->context, $dest->value, $ptr);
                } else {
                    $this->context->builder->store($ptr, $dest->value);
                }
                $dest->addref();
                $this->copyValueBoxJitFlags($dest, $source);

                return;
            }
            $toStore = $value;
            if ('__value__*' === $valueTy && '__value__' === $destTy) {
                $toStore = $this->context->builder->load($value);
            }
            $this->context->builder->store($toStore, $dest->value);
            $dest->addref();
            $this->copyValueBoxJitFlags($dest, $source);

            return;
        }
        $this->assignOperand($result, $source);
    }

    private function syncCompileTimeString(Variable $dest, Variable $src, bool $force): void
    {
        if ($force || null !== $src->compileTimeString) {
            $dest->compileTimeString = $src->compileTimeString;
        }
        if ($force || null !== $src->classUserType) {
            $dest->classUserType = $src->classUserType;
        }
    }

    private function syncCompileTimeFloat(Variable $dest, Variable $src, bool $force): void
    {
        if ($force || null !== $src->compileTimeFloat) {
            $dest->compileTimeFloat = $src->compileTimeFloat;
        }
    }

    private function syncCompileTimeBcmathNumber(Variable $dest, Variable $src, bool $force): void
    {
        // Only propagate present metadata. Force-merge must not wipe construct-time
        // Number value/scale used for AOT fold (#24683); script-global invalidate
        // clears then re-syncs from the assign source.
        if (null !== $src->compileTimeBcmathNumber) {
            $dest->compileTimeBcmathNumber = $src->compileTimeBcmathNumber;
        }
    }

    private function syncCompileTimeDomTagName(Variable $dest, Variable $src, bool $force): void
    {
        if ($force || null !== $src->compileTimeDomTagName) {
            $dest->compileTimeDomTagName = $src->compileTimeDomTagName;
        }
        if ($force || null !== $src->compileTimeDomInnerXml) {
            $dest->compileTimeDomInnerXml = $src->compileTimeDomInnerXml;
        }
        if ($force || null !== $src->compileTimeDomInnerXmlParent) {
            $dest->compileTimeDomInnerXmlParent = $src->compileTimeDomInnerXmlParent;
        }
        // firstChild/lastChild index for thin-AOT replaceChild INNER_XML rebuild (#28671).
        if ($force || null !== $src->compileTimeDomChildIndex) {
            $dest->compileTimeDomChildIndex = $src->compileTimeDomChildIndex;
        }
        if ($force || null !== $src->compileTimeDomNodePath) {
            $dest->compileTimeDomNodePath = $src->compileTimeDomNodePath;
        }
        if ($force || null !== $src->compileTimeDomLineNo) {
            $dest->compileTimeDomLineNo = $src->compileTimeDomLineNo;
        }
        if ($force || null !== $src->compileTimeDomTextData) {
            $dest->compileTimeDomTextData = $src->compileTimeDomTextData;
        }
        if ($force || null !== $src->compileTimeDomAttributes) {
            // Copy the bag — a shared array ref lets later setAttribute / mutation see the
            // wrong identity after replaceChild synced oldChild onto the result (#35386).
            if (null === $src->compileTimeDomAttributes) {
                $dest->compileTimeDomAttributes = null;
            } else {
                $copied = [];
                foreach ($src->compileTimeDomAttributes as $attrName => $attrVal) {
                    $copied[$attrName] = $attrVal;
                }
                $dest->compileTimeDomAttributes = $copied;
            }
        }
        if ($force || null !== $src->compileTimeDomElementId) {
            $dest->compileTimeDomElementId = $src->compileTimeDomElementId;
        }
        if ($force || null !== $src->compileTimeDomAttrLocalName) {
            $dest->compileTimeDomAttrLocalName = $src->compileTimeDomAttrLocalName;
        }
        if ($force || null !== $src->compileTimeDomAttrNamespace) {
            $dest->compileTimeDomAttrNamespace = $src->compileTimeDomAttrNamespace;
        }
        if ($force || null !== $src->compileTimeDomLoadXml) {
            $dest->compileTimeDomLoadXml = $src->compileTimeDomLoadXml;
        }
        if ($force || null !== $src->compileTimeDomNodeListLength) {
            $dest->compileTimeDomNodeListLength = $src->compileTimeDomNodeListLength;
        }
        if ($force || null !== $src->compileTimeDomImportHostSxeToken) {
            $dest->compileTimeDomImportHostSxeToken = $src->compileTimeDomImportHostSxeToken;
        }
    }

    private function syncCompileTimeDatePeriod(Variable $dest, Variable $src, bool $force): void
    {
        if ($force || null !== $src->compileTimeLong) {
            $dest->compileTimeLong = $src->compileTimeLong;
        }
        if ($force || null !== $src->compileTimeDatePeriodTimestamps) {
            $dest->compileTimeDatePeriodTimestamps = $src->compileTimeDatePeriodTimestamps;
            $dest->compileTimeDatePeriodTimezone = $src->compileTimeDatePeriodTimezone;
        }
        if ($force || \is_array($src->compileTimeDatePeriodSerialize)) {
            $dest->compileTimeDatePeriodSerialize = $src->compileTimeDatePeriodSerialize;
        }
        if ($force || null !== $src->compileTimeDateInterval) {
            $dest->compileTimeDateInterval = $src->compileTimeDateInterval;
        }
        // DateTimeZone zone id — must not share compileTimeString with class name (#29732).
        if ($force || null !== $src->compileTimeTimezoneName) {
            $dest->compileTimeTimezoneName = $src->compileTimeTimezoneName;
        }
        if ($force || null !== $src->compileTimeDateTimeTimestamp) {
            $dest->compileTimeDateTimeTimestamp = $src->compileTimeDateTimeTimestamp;
            $dest->compileTimeDateTimeMicrosecond = $src->compileTimeDateTimeMicrosecond;
        }
    }

    /**
     * Record that a named local holds a DateTimeZone with a known id (#29732).
     */
    private function noteDateTimeZoneLocal(Operand $resultOp, Variable $value): void
    {
        if (null === $value->compileTimeTimezoneName || '' === $value->compileTimeTimezoneName) {
            return;
        }
        $assignedName = JIT\OperandName::resolve($resultOp);
        if (null === $assignedName || '' === $assignedName) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($assignedName);
        $this->context->lastAssignedDateTimeZoneLocalName = $resolved;
        $this->context->dateTimeZoneLocalNames[$resolved] = $value->compileTimeTimezoneName;
        $this->context->bindVariableByName($resolved, $value);
        $this->context->scope->variables[$resultOp] = $value;
    }

    /** Record that a named local holds a DateTime instant (#32691). */
    private function noteDateTimeLocal(Operand $resultOp, Variable $value): void
    {
        if (null === $value->compileTimeDateTimeTimestamp) {
            return;
        }
        $assignedName = JIT\OperandName::resolve($resultOp);
        if (null === $assignedName || '' === $assignedName) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($assignedName);
        $this->context->dateTimeLocalInstants[$resolved] = [
            'timestamp' => (int) $value->compileTimeDateTimeTimestamp,
            'timezone' => $value->compileTimeTimezoneName,
            'microsecond' => (int) ($value->compileTimeDateTimeMicrosecond ?? 0),
            'className' => $value->compileTimeDateTimeClassName ?? $value->classUserType ?? 'DateTime',
        ];
        $this->context->bindVariableByName($resolved, $value);
    }

    private function copyValueBoxJitFlags(Variable $dest, Variable $src, bool $force = false): void
    {
        if (Variable::TYPE_VALUE !== $dest->type || Variable::TYPE_VALUE !== $src->type) {
            return;
        }
        $dest->valueBoxHashtable = $src->valueBoxHashtable;
        $dest->compileTimeEmptyArrayLiteral = $src->compileTimeEmptyArrayLiteral;
        $dest->isNullConstant = $src->isNullConstant;
        $dest->compileTimeConstantName = $src->compileTimeConstantName;
        $dest->compileTimeEnumCase = $src->compileTimeEnumCase;
        $dest->compileTimeLong = $src->compileTimeLong;
        $this->syncCompileTimeString($dest, $src, $force);
        $this->syncCompileTimeFloat($dest, $src, $force);
        $this->syncCompileTimeBcmathNumber($dest, $src, $force);
        $this->syncCompileTimeDomTagName($dest, $src, $force);
        $this->syncCompileTimeDatePeriod($dest, $src, $force);
    }

    /** Keep borrowed object-property hashtable metadata on locals ($cfg = $this->config, #848). */
    private function maybeCopyObjectPropertyBacking(Variable $dest, Variable $src, bool $force): void
    {
        // Branch-merge assigns (?-> / ??) must read the unified __value__ slot at the merge block (#3219).
        // ??= write-fetch is the exception: dropping objectPropertySlot loses the store (#33748).
        if (
            $force
            && $this->context->retainCoalesceInstancePropertyLvalue
            && null !== $src->objectPropertySlot
        ) {
            $this->copyObjectPropertyBacking($dest, $src);
        } elseif ($force) {
            $dest->objectPropertySlot = null;
            $dest->objectPropertyType = null;
            $dest->objectPropertyReceiver = null;
            $dest->objectPropertyName = null;
            $dest->objectPropertyClassName = null;
            $dest->objectPropertyDnfArms = null;
            $dest->objectPropertyClassConstraint = null;
            $dest->objectPropertyDeclaredTypeLabel = null;
        } elseif ($this->isScalarObjectPropertyAliasType($src->objectPropertyType)) {
            // Scalar prop reads: copy the value only (#34465 / peer #33849).
            $dest->objectPropertySlot = null;
            $dest->objectPropertyType = null;
            $dest->objectPropertyReceiver = null;
            $dest->objectPropertyReceiverOp = null;
            $dest->objectPropertyName = null;
            $dest->objectPropertyClassName = null;
            $dest->objectPropertyDnfArms = null;
            $dest->objectPropertyClassConstraint = null;
            $dest->objectPropertyDeclaredTypeLabel = null;
        } else {
            $this->copyObjectPropertyBacking($dest, $src);
        }
        if (JIT\GeneratorHelper::isGeneratorVariable($src)) {
            $dest->generatorStatePtr = $src->generatorStatePtr;
            $dest->generatorResumeName = $src->generatorResumeName;
            $dest->isJitGenerator = $src->isJitGenerator;
        }
        if (null !== $src->closureCall) {
            $dest->closureCall = $src->closureCall;
        }
        if (null !== $src->foreachClosureProxyTable) {
            $dest->foreachClosureProxyTable = $src->foreachClosureProxyTable;
            $dest->foreachContainerSlotKey = $src->foreachContainerSlotKey;
        }
        if ($src->closureIsStatic) {
            $dest->closureIsStatic = true;
        }
        if ($src->closureIsMethodFake) {
            $dest->closureIsMethodFake = true;
        }
        if (null !== $src->fiberResumeName) {
            $dest->fiberResumeName = $src->fiberResumeName;
            $dest->fiberStatePtr = $src->fiberStatePtr;
        }
    }

    private function copyObjectPropertyBacking(Variable $dest, Variable $src): void
    {
        $dest->objectPropertySlot = $src->objectPropertySlot;
        $dest->objectPropertyType = $src->objectPropertyType;
        $dest->objectPropertyReceiver = $src->objectPropertyReceiver;
        $dest->objectPropertyReceiverOp = $src->objectPropertyReceiverOp;
        $dest->objectPropertyName = $src->objectPropertyName;
        $dest->objectPropertyClassName = $src->objectPropertyClassName;
        $dest->objectPropertyDnfArms = $src->objectPropertyDnfArms;
        $dest->objectPropertyClassConstraint = $src->objectPropertyClassConstraint;
        $dest->objectPropertyDeclaredTypeLabel = $src->objectPropertyDeclaredTypeLabel;
        $dest->closureCall = $src->closureCall;
        $dest->closureIsStatic = $src->closureIsStatic;
        $dest->closureIsMethodFake = $src->closureIsMethodFake;
        if (null !== $src->foreachClosureProxyTable) {
            $dest->foreachClosureProxyTable = $src->foreachClosureProxyTable;
            $dest->foreachContainerSlotKey = $src->foreachContainerSlotKey;
        }
        $dest->generatorStatePtr = $src->generatorStatePtr;
        $dest->generatorResumeName = $src->generatorResumeName;
        $dest->isJitGenerator = $src->isJitGenerator;
        $dest->fiberResumeName = $src->fiberResumeName;
        $dest->fiberStatePtr = $src->fiberStatePtr;
        if (Variable::TYPE_OBJECT === $src->type && Variable::TYPE_OBJECT === $dest->type) {
            $srcKey = spl_object_id($this->context->helper->loadValue($src));
            $destKey = spl_object_id($this->context->helper->loadValue($dest));
            if (isset($this->context->fiberResumeByObjectValueId[$srcKey])) {
                $this->context->fiberResumeByObjectValueId[$destKey]
                    = $this->context->fiberResumeByObjectValueId[$srcKey];
            }
        }
    }

    /**
     * Write a JIT value into an embedded {@see __value__} field on generator state (#3074).
     */
    public function assignValueToGeneratorField(
        \PHPLLVM\Value $destField,
        Variable $src,
        ?Operand $srcOp
    ): void {
        $destPtr = JIT\JitValueBox::normalizeValuePtr($this->context, $destField);
        if (JIT\Variable::TYPE_STRING === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeString'),
                $destPtr,
                $this->context->helper->loadValue($src)
            );

            return;
        }
        if (JIT\Variable::TYPE_NATIVE_LONG === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $destPtr,
                $this->context->helper->loadValue($src)
            );

            return;
        }
        if (JIT\Variable::TYPE_NATIVE_DOUBLE === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeDouble'),
                $destPtr,
                $this->context->helper->loadValue($src)
            );

            return;
        }
        if (JIT\Variable::TYPE_NATIVE_BOOL === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeBool'),
                $destPtr,
                $this->context->helper->loadValue($src)
            );

            return;
        }
        if (JIT\Variable::TYPE_NULL === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeNull'),
                $destPtr
            );

            return;
        }
        if (JIT\Variable::TYPE_VALUE === $src->type) {
            JIT\JitValueBox::copyFromPointer(
                $this->context,
                $destField,
                JIT\JitValueBox::valuePtrFromVariable($this->context, $src)
            );

            return;
        }
        if (JIT\Variable::TYPE_OBJECT === $src->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeObject'),
                $destPtr,
                $this->context->helper->loadValue($src)
            );

            return;
        }
        if (JIT\Variable::TYPE_HASHTABLE === $src->type) {
            $ht = $this->context->helper->loadValue($src);
            $this->context->refcount->addref($ht);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                $destPtr,
                $ht
            );

            return;
        }
        if (0 !== ($src->type & JIT\Variable::IS_NATIVE_ARRAY)) {
            // materialize addrefs to rc=1; writeHashtable → rc=2; delref → sole owner (#36388).
            $htPtr = JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $src);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                $destPtr,
                $htPtr
            );
            $this->context->refcount->delref(
                $this->context->builder->pointerCast(
                    $htPtr,
                    $this->context->getTypeFromString('__ref__virtual*')
                )
            );

            return;
        }
        if (null !== $srcOp) {
            $lit = $srcOp instanceof Operand\Literal ? $srcOp : null;
            if (null !== $lit && null !== $lit->type) {
                $boxed = JIT\Variable::fromLiteral($this->context, $lit);
                $this->assignValueToGeneratorField($destField, $boxed, null);

                return;
            }
        }
        throw new \LogicException('Unsupported generator yield value type in JIT (issue #3074)');
    }
}
