<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPTypes\Type;
use PHPCompiler\Compiler\AttributeClassRegistry;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\JIT\Builtin\AttributeRegistry;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\IssetHelper;
use PHPCompiler\JIT\SelfHostBuiltinPolicy;
use PHPCompiler\JIT\Variable;
use PHPCompiler\Func as CoreFunc;
use PHPLLVM;
use PHPCompiler\Block;
use PHPCompiler\OpCode;

/**
 * Operand assignment lowering for JIT/AOT.
 *
 * Extracted from {@see \PHPCompiler\JIT} (#36403).
 */
trait AssignOperand
{
    private function assignOperand(Operand $resultOp, Variable $value, bool $force = false): void {
        // `$s = $obj->scalarProp` must not leave a live property alias on `$s` (#34465).
        if (!(
            $force
            && $this->context->retainCoalesceInstancePropertyLvalue
        )) {
            $value = $this->detachScalarObjectPropertyAliasForAssign($value);
        }
        $value = $this->coerceNamedLocalNativeLongPropertyAssign($resultOp, $value);
        $branchMergeTarget = $force && $this->context->coalesceAssignTargets->contains($resultOp);
        // ?: false arm (`'null'`) after the true arm fetched `$o->tagName`: bindPropertyFetchResult
        // can leave objectPropertySlot on a TYPE_VALUE phi temp, so a later scalar assign skips
        // assignToPointer and echo/`$x =` reads empty DOMElement::$tagName (#23514 / #33849).
        // Only reseat forced coalesce/ternary merges. `$obj->prop = $rhs` is also TYPE_VALUE
        // with a live slot (php-cfg PROPERTY_FETCH + ASSIGN) — stripping it made AOT ignore
        // untyped/string instance writes (leftover of #35863 / #35874).
        // `$r =& $obj->prop; $r = N` keeps objectPropertySlot via assignRefLvalueAlias — reseating
        // here made the write a local-box no-op (silent wrong output; #35898 / leftover of #34649).
        if (
            $force
            && null === $value->objectPropertySlot
            && !$this->context->retainCoalesceInstancePropertyLvalue
            && $this->context->hasVariableOp($resultOp)
        ) {
            $mergeDest = $this->context->getVariableFromOp($resultOp);
            if (
                null !== $mergeDest->objectPropertySlot
                && !$mergeDest->assignRefLvalueAlias
                && Variable::TYPE_VALUE === $mergeDest->type
                && null === $mergeDest->staticPropertyGlobal
                && !$mergeDest->functionStaticGlobal
            ) {
                $this->reseatCoalesceResultAfterPropertyArms($resultOp);
            }
        }
        $resolvedName = \PHPCompiler\JIT\OperandName::resolve($resultOp);
        // Propagate empty-array markers onto the assign destination before DateTime New_
        // sync can mistake `$out = []` for a pending object local (#34461).
        if ($value->compileTimeEmptyArrayLiteral || $value->valueBoxHashtable || Variable::TYPE_HASHTABLE === $value->type) {
            if ($this->context->hasVariableOp($resultOp)) {
                $destEarly = $this->context->getVariableFromOp($resultOp);
                if ($value->compileTimeEmptyArrayLiteral || Variable::TYPE_HASHTABLE === $value->type) {
                    $destEarly->compileTimeEmptyArrayLiteral = $destEarly->compileTimeEmptyArrayLiteral
                        || $value->compileTimeEmptyArrayLiteral
                        || (Variable::TYPE_HASHTABLE === $value->type && 0 === $value->nextFreeElement);
                }
                if (Variable::TYPE_VALUE === $destEarly->type) {
                    $destEarly->valueBoxHashtable = true;
                }
            }
        }
        // Generator create results carry resume/state tags — first-bind must not strip them via
        // makeVariableFromValueOp, or foreach ($g as …) misses the Generator ITER path (#28624).
        if ($value->isJitGenerator) {
            $this->context->setVariableOp($resultOp, $value);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $value);
            }
            $this->markScopeVariableAssignedIfTracked($resultOp, $value);

            return;
        }
        if ($this->rebindAssignLvalueFromByRefFormalOrName($resultOp)) {
            // operand now aliases the live by-ref formal / named binding
        }
        if (!$this->context->hasVariableOp($resultOp) && null !== $resolvedName && '' !== $resolvedName) {
            $boundName = $this->context->resolveRefAliasName($resolvedName);
            if (isset($this->context->namedVariableBindings[$boundName])) {
                $boundLv = $this->context->namedVariableBindings[$boundName];
                if (
                    null !== $boundLv->staticPropertyGlobal
                    || $boundLv->functionStaticGlobal
                    || null !== $boundLv->objectPropertySlot
                    || null !== $boundLv->valueBoxAliasPtr
                    || null !== $boundLv->writableHt
                    || $boundLv->assignRefLvalueAlias
                ) {
                    $this->context->setVariableOp($resultOp, $boundLv);
                }
            }
        }
        if (!$this->context->hasVariableOp($resultOp)) {
            if (
                null !== $this->context->jitCurrentBlock
                && $this->context->aliasVariableOpFromSlot($this->context->jitCurrentBlock, $resultOp)
            ) {
                // fall through to normal assign on the aliased lvalue
            } elseif ($this->tryAssignScriptGlobalFirstBinding($resultOp, $value)) {
                return;
            } elseif (
                Variable::TYPE_VALUE === $value->type
                && Variable::KIND_VALUE === $value->kind
                && '__value__*' === $this->context->getStringFromType($this->context->helper->loadValue($value)->typeOf())
            ) {
                // getenv() and similar return __value__* rvalues — copy into a stack slot (#8555).
                $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                    $this->context,
                    $slot,
                    \PHPCompiler\JIT\JitValueBox::normalizeValuePtr(
                        $this->context,
                        $this->context->helper->loadValue($value)
                    )
                );
                $var = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $var->addref();
                $this->copyValueBoxJitFlags($var, $value, false);
                $this->context->setVariableOp($resultOp, $var);
                $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $var);
                }
                // Method formals with `__value__` ABI (e.g. Router::dispatch $route) bind here
                // without falling through to the markAssigned path (#31101 MiniWebApp stderr).
                $this->markScopeVariableAssignedIfTracked($resultOp, $var);

                return;
            } elseif (
                Variable::TYPE_VALUE === $value->type
                && Variable::KIND_VARIABLE === $value->kind
            ) {
                // HT dim-fetch copies into stack __value__ slots — first-bind must copy the
                // slot, not loadValue()+makeVariableFromValueOp (AOT abort / empty chain) (#31938).
                // Also copy isNullConstant / compile-time flags — ConstFetch null for
                // mb_trim($s, null, $enc) otherwise loses the null marker (#35199).
                $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                    $this->context,
                    $slot,
                    \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $value)
                );
                $var = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $var->addref();
                $this->copyValueBoxJitFlags($var, $value, false);
                $this->context->setVariableOp($resultOp, $var);
                $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $var);
                }
                $this->markScopeVariableAssignedIfTracked($resultOp, $var);

                return;
            } elseif (
                Variable::TYPE_NATIVE_LONG === $value->type
                && Variable::KIND_VALUE === $value->kind
                && null !== $value->value
                && \PHPLLVM\Value::KIND_CONSTANT_INT === $value->value->getKind()
                && null !== ($initName = \PHPCompiler\JIT\OperandName::resolve($resultOp))
                && '' !== $initName
                && null !== $this->context->jitEnclosingBlock?->func
                && !$this->context->jitEnclosingBlock->isMainScript()
            ) {
                // Named function locals must live in an i64 alloca so loop JUMPIF and
                // post-increment share one slot — KIND_VALUE literals go stale (#36018).
                $i64 = $this->context->getTypeFromString('int64');
                $slot = \PHPCompiler\JIT\BasicBlockHelper::entryAlloca($this->context, $i64);
                $this->context->builder->store($this->context->helper->loadValue($value), $slot);
                $var = new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $var->addref();
                $this->context->setVariableOp($resultOp, $var);
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($initName),
                    $var
                );
                $this->markScopeVariableAssignedIfTracked($resultOp, $var);

                return;
            } else {
                // it's a kind!
                $var = $this->context->makeVariableFromValueOp($this->context->helper->loadValue($value), $resultOp);
                $var->compileTimeConstantName = $value->compileTimeConstantName;
                $var->compileTimeEnumCase = $value->compileTimeEnumCase;
                $var->compileTimeFloat = $value->compileTimeFloat;
                $var->compileTimeLong = $value->compileTimeLong;
                $this->syncCompileTimeString($var, $value, false);
                // First-bind must keep Closure invoke metadata; dropping it forces
                // RuntimeIndirectClosureCall over every {closure}_* in the module and
                // mis-stores __value__ returns into __value__** (#23973, e20_closure).
                // Also bind by name so `$f = function() use ($n) {}` keeps captures (#24106).
                $this->preserveClosureInvokeMetadata($resultOp, $var, $value);
                $this->markScopeVariableAssignedIfTracked($resultOp, $var);

                return;
            }
        }
        $result = $this->resolveAssignLvalue($resultOp);
        $result = $this->ensureNamedNativeLongLocalAlloca($resultOp, $result);
        // Locals that still carry a non-hashtable property alias from a prior read assign
        // must rebind — writing through would mutate the previous object (#34465).
        // Intentional ASSIGN_REF aliases (`$o->p =& $v`) must keep the slot (#34649).
        if (
            null !== $result->objectPropertySlot
            && $this->isScalarObjectPropertyAliasType($result->objectPropertyType)
            && !$result->assignRefLvalueAlias
            && !(
                $force
                && $this->context->retainCoalesceInstancePropertyLvalue
            )
        ) {
            $lvName = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $lvName && '' !== $lvName) {
                $result->objectPropertySlot = null;
                $result->objectPropertyType = null;
                $result->objectPropertyReceiver = null;
                $result->objectPropertyReceiverOp = null;
                $result->objectPropertyName = null;
                $result->objectPropertyClassName = null;
                $result->objectPropertyDnfArms = null;
            }
        }
        if (
            null !== $this->context->listUnpackAssignRootBlock
            && Variable::TYPE_VALUE === $result->type
            && Variable::KIND_VARIABLE !== $result->kind
            && null === $result->objectPropertySlot
            && null === $result->staticPropertyGlobal
            && !$result->functionStaticGlobal
        ) {
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            $promoted = new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VARIABLE,
                $slot
            );
            $this->context->setVariableOp($resultOp, $promoted);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $promoted);
            }
            $result = $promoted;
            $this->recordListUnpackAssignSlot($resultOp, $result);
        }
        $globalTarget = $this->resolveScriptGlobalAssignTarget($resultOp, $result);
        if (null !== $globalTarget) {
            $globalPtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $globalTarget);
            \PHPCompiler\JIT\JitValueBox::assignToPointer(
                $this->context,
                $globalPtr,
                $value
            );
            \PHPCompiler\JIT\JitValueBox::publishAfterWrite($this->context, $globalPtr);
            $this->preserveClosureInvokeMetadata($resultOp, $globalTarget, $value);
            $this->invalidateScriptGlobalCompileTimeMetadata($globalTarget);
            $globalTarget->compileTimeEnumCase = $value->compileTimeEnumCase;
            $this->syncCompileTimeString($globalTarget, $value, false);
            $this->syncCompileTimeFloat($globalTarget, $value, false);
            $this->syncCompileTimeBcmathNumber($globalTarget, $value, false);
            $this->syncCompileTimeDomTagName($globalTarget, $value, false);
            $this->syncCompileTimeDatePeriod($globalTarget, $value, false);
            $classTag = $value->classUserType ?? null;
            if (is_string($classTag) && '' !== $classTag) {
                $globalTarget->classUserType = $classTag;
                $globalTarget->isNullConstant = false;
                $resultOp->type = new Type(Type::TYPE_OBJECT, [], $classTag);
            }
            $this->context->setVariableOp($resultOp, $globalTarget);
            $globalName = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $globalName && '' !== $globalName) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($globalName),
                    $globalTarget
                );
            }
            $this->markScopeVariableAssignedIfTracked($resultOp, $globalTarget);

            return;
        }
        if ($result === $value) {
            // Param prologue can bind the LLVM formal Variable as the CV then re-assign
            // the same object — still mark assigned so undefined-variable guards stay quiet
            // (Router::dispatch $route as `__value__` ABI, #31101).
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        }
        // Foreach by-ref must use hashtable index writes, not valueBoxAliasPtr (#4364, AOT {main}).
        if (null !== $result->foreachByRefPackedArm) {
            \PHPCompiler\JIT\HashTableHelper::assignForeachByRefWritable($this->context, $result, $value);
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        }
        if (
            $result->borrowedValueEntry
            && null !== $result->writableHt
            && null !== $result->writableIndex
        ) {
            \PHPCompiler\JIT\HashTableHelper::setAtIndex(
                $this->context,
                $result->writableHt,
                $result->writableIndex,
                $value
            );
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        }
        // Reference aliases to object properties keep objectPropertySlot; guard before
        // valueBoxAliasPtr writes so readonly checks are not skipped (#4273, #3149).
        if (null !== $result->valueBoxAliasPtr && null === $result->objectPropertySlot) {
            if (
                Variable::TYPE_VALUE === $value->type
                && Variable::KIND_VARIABLE === $value->kind
                && '__value__' === $this->context->getStringFromType($value->value->typeOf())
            ) {
                \PHPCompiler\JIT\JitValueBox::copyIntoPointer(
                    $this->context,
                    $result->valueBoxAliasPtr,
                    \PHPCompiler\JIT\JitValueBox::pointer($this->context, $value->value)
                );
            } else {
                \PHPCompiler\JIT\JitValueBox::assignToPointer(
                    $this->context,
                    $result->valueBoxAliasPtr,
                    $value
                );
            }
            \PHPCompiler\JIT\JitValueBox::publishAfterWrite($this->context, $result->valueBoxAliasPtr);
            $this->preserveClosureInvokeMetadata($resultOp, $result, $value);
            $this->recordListUnpackAssignSlot($resultOp, $result);
            $this->syncAssignRefHtEntriesFromShared($result);

            return;
        }
        // DOMElement::$textContent / $nodeValue — before temp promotion clears receiver (#23251).
        if ($this->context->extensionLowering->tryDomTextContentStore(
            $this->context,
            $result,
            $value
        )) {
            return;
        }
        // __set / ARRAY_AS_PROPS lvalues are KIND_VALUE Temporary placeholders — must dispatch
        // before temp→stack promotion, which allocates a fresh Variable and drops the markers
        // (AOT silent no-op; Zend/zend_object_handlers.c zend_std_write_property).
        if (null !== $result->magicSetReceiver && null !== $result->magicSetName) {
            if (\PHPCompiler\JIT\UserScriptAotEnv::isActive()) {
                $sxePropSet = $this->context->extensionLowering->tryPropertySet(
                    $this->context,
                    $result,
                    $result->magicSetName,
                    $value
                );
                if (null !== $sxePropSet) {
                    return;
                }
            }
            $receiverVar = new Variable(
                $this->context,
                Variable::TYPE_OBJECT,
                Variable::KIND_VALUE,
                $result->magicSetReceiver
            );
            $receiverVar->objectPropertyClassName = $result->objectPropertyClassName;
            if (\PHPCompiler\JIT\MagicMethodDispatch::tryEmitMagicSet(
                $this->context,
                $receiverVar,
                $result->magicSetName,
                $value,
                $this->context->jitEnclosingBlock
            )) {
                return;
            }
        }
        if (null !== $result->arrayAsPropsReceiver && null !== $result->arrayAsPropsName) {
            \PHPCompiler\VM\ArrayObjectJitHelper::compilePropertyAssign($this->context, $result, $value);

            return;
        }
        if (
            $force
            && Variable::KIND_VALUE === $result->kind
            && (
                Variable::TYPE_STRING !== $result->type
                || !\PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($result, $this->context)
            )
            && !$value->isJitGenerator
            && null === $result->objectPropertySlot
            && null === $result->staticPropertyGlobal
            && !$result->functionStaticGlobal
            && null === $result->magicSetReceiver
            && null === $result->arrayAsPropsReceiver
        ) {
            // ?? left branch fetch binds a superglobal lvalue; force-assign needs a stack slot (#866).
            // Property lvalues keep objectPropertySlot so ReadonlyClassGuard runs on inc/dec (#3149).
            // Class static property lvalues keep staticPropertyGlobal (#20877).
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            $this->context->setVariableOp(
                $resultOp,
                new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                )
            );
            $result = $this->context->getVariableFromOp($resultOp);
        }
        if (
            ($resultOp instanceof \PHPCfg\Operand\Temporary || $resultOp instanceof \PHPCfg\Operand\Literal)
            && Variable::KIND_VALUE === $result->kind
            && null === $result->objectPropertySlot
            && null === $result->staticPropertyGlobal
            && null === $result->magicSetReceiver
            && null === $result->arrayAsPropsReceiver
            && (
                Variable::TYPE_STRING !== $result->type
                || !\PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($result, $this->context)
            )
        ) {
            // Temporaries/literals can start life as rvalues; promote to a boxed stack slot on first assignment.
            // Skip class static property lvalues — they must store via staticPropertyGlobal (#20877).
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            $this->context->setVariableOp(
                $resultOp,
                new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                )
            );
            $result = $this->context->getVariableFromOp($resultOp);
        }
        if (
            null !== $result->objectPropertySlot
            && !(
                $force
                && $this->context->retainCoalesceInstancePropertyLvalue
            )
        ) {
            if (null === $result->objectPropertyType) {
                throw new \LogicException('objectPropertySlot requires objectPropertyType');
            }
            \PHPCompiler\JIT\DynamicObjectReadonlyGuard::emitBeforePropertyStore(
                $this->context,
                $result,
                $this->context->jitEnclosingBlock
            );
            \PHPCompiler\JIT\ReadonlyClassGuard::emitBeforePropertyStore(
                $this->context,
                $result,
                $this->context->jitEnclosingBlock,
                'modify',
                $this
            );
            if (\PHPCompiler\JIT\AsymmetricVisibilityGuard::emitBeforePropertyStore(
                $this->context,
                $this,
                $result,
                $this->context->jitEnclosingBlock
            )) {
                return;
            }
            if (\PHPCompiler\JIT\PropertyHookDispatch::emitSetHookIfNeeded(
                $this->context,
                $result,
                $value,
                $this->context->jitEnclosingBlock,
                $this
            )) {
                return;
            }
            if (null !== $result->objectPropertyDnfArms) {
                \PHPCompiler\JIT\DnfParamCheck::enforcePropertyWrite(
                    $this->context,
                    $value,
                    $result->objectPropertyDnfArms
                );
            } elseif (
                null !== $result->objectPropertyClassConstraint
                && '' !== $result->objectPropertyClassConstraint
            ) {
                \PHPCompiler\JIT\TypedPropertyClassAssignCheck::enforce(
                    $this->context,
                    $value,
                    $result->objectPropertyClassConstraint,
                    $result->objectPropertyClassName ?? '',
                    $result->objectPropertyName ?? 'property',
                    $result->objectPropertyDeclaredTypeLabel ?? $result->objectPropertyClassConstraint,
                    $result->objectPropertyAllowsNull
                );
            }
            if (null !== $result->objectPropertySlot) {
                \PHPCompiler\JIT\BasicBlockHelper::continueAfterDefiningValue(
                    $this->context,
                    $result->objectPropertySlot
                );
            }
            \PHPCompiler\JIT\ReadonlyClassGuard::emitStoreUnlessPending(
                $this->context,
                function () use ($result, $value): void {
                    $constraint = strtolower(ltrim((string) ($result->objectPropertyClassConstraint ?? ''), '\\'));
                    if (\in_array($constraint, ['datetime', 'datetimeimmutable'], true)) {
                        $valueClass = strtolower(ltrim((string) (
                            $value->compileTimeDateTimeClassName ?? $value->classUserType ?? ''
                        ), '\\'));
                        // Reused New_ temps keep the prior DateTime(Immutable) class hint and block
                        // pending instant copy — typed property stores get an empty shell (#35802).
                        if ('' !== $valueClass && $valueClass !== $constraint) {
                            $value->compileTimeDateTimeTimestamp = null;
                            $value->compileTimeDateTimeMicrosecond = null;
                            $value->compileTimeTimezoneName = null;
                            $value->compileTimeDateTimeClassName = null;
                            if (\in_array($valueClass, ['datetime', 'datetimeimmutable'], true)) {
                                $value->classUserType = null;
                            }
                        }
                    }
                    $pending = $this->context->pendingDateTimePropertyInstant;
                    if (
                        is_array($pending)
                        && isset($pending['timestamp'])
                        && null === $value->compileTimeDateTimeTimestamp
                    ) {
                        $pendingClass = strtolower(ltrim((string) ($pending['className'] ?? 'datetime'), '\\'));
                        if (
                            \in_array($constraint, ['datetime', 'datetimeimmutable'], true)
                            && $pendingClass === $constraint
                        ) {
                            $value->compileTimeDateTimeTimestamp = (int) $pending['timestamp'];
                            $value->compileTimeDateTimeMicrosecond = (int) ($pending['microsecond'] ?? 0);
                            $value->compileTimeTimezoneName = $pending['timezone'] ?? null;
                            if (null === $value->classUserType || '' === $value->classUserType) {
                                $value->classUserType = 'datetimeimmutable' === $constraint
                                    ? 'DateTimeImmutable'
                                    : 'DateTime';
                            }
                            if (null === $value->compileTimeDateTimeClassName || '' === $value->compileTimeDateTimeClassName) {
                                $value->compileTimeDateTimeClassName = $value->classUserType;
                            }
                        }
                    }
                    $this->context->pendingDateTimePropertyInstant = null;
                    $this->context->type->object->propertyStore(
                        $result->objectPropertySlot,
                        $value,
                        $result->objectPropertyType
                    );
                }
            );

            return;
        }
        if (null !== $result->staticPropertyGlobal) {
            if (null === $result->staticPropertyType) {
                throw new \LogicException('staticPropertyGlobal requires staticPropertyType');
            }
            if (\PHPCompiler\JIT\AsymmetricVisibilityGuard::emitBeforeStaticPropertyStore(
                $this->context,
                $this,
                $result,
                $this->context->jitEnclosingBlock
            )) {
                return;
            }
            if (\PHPCompiler\JIT\PropertyHookDispatch::emitStaticSetHookIfNeeded(
                $this->context,
                $result,
                $value,
                $this->context->jitEnclosingBlock,
                $this
            )) {
                return;
            }
            if (null !== $result->staticPropertyDnfArms) {
                \PHPCompiler\JIT\DnfParamCheck::enforcePropertyWrite(
                    $this->context,
                    $value,
                    $result->staticPropertyDnfArms
                );
            }
            $this->context->type->object->staticPropertyStore(
                $result->staticPropertyGlobal,
                $value,
                $result->staticPropertyType,
                $result->staticPropertyInitGlobal
            );

            return;
        }
        if ($result->functionStaticGlobal) {
            \PHPCompiler\JIT\JitValueBox::assignToPointer(
                $this->context,
                \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $result),
                $value
            );
            // Script globals reuse functionStaticGlobal; keep valueBoxHashtable so
            // FETCH_DIM_W does not take ValueBoxDimWrite (#32830 / #32814).
            $this->copyValueBoxJitFlags($result, $value);
            $this->invalidateScriptGlobalCompileTimeMetadata($result);
            $this->syncCompileTimeBcmathNumber($result, $value, false);
            $this->syncCompileTimeDomTagName($result, $value, false);
            $this->syncCompileTimeDatePeriod($result, $value, false);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($resolved),
                    $result
                );
            }

            return;
        }
        if (null !== $result->writableHt && null !== $result->writableValueBoxKey) {
            \PHPCompiler\JIT\HashTableHelper::setValueBoxKey(
                $this->context,
                $result->writableHt,
                $result->writableValueBoxKey,
                $value
            );
            $this->syncDimWriteOrphanValueBox($result, $value);

            return;
        }
        // prepareStringKeyWrite / prepareIndexWrite lvalues: must commit into the HT
        // (null assigns otherwise drop the key — #21947). Also sync the orphan value-box
        // so `$r0 = $a[0] = 99` / array-literal packing can read the assign expression
        // value from the same Variable (#24055; AOT e30).
        if (null !== $result->writableHt && null !== $result->writableStringKey) {
            \PHPCompiler\JIT\HashTableHelper::setAtStringKey(
                $this->context,
                $result->writableHt,
                $result->writableStringKey,
                $value
            );
            $this->syncDimWriteOrphanValueBox($result, $value);

            return;
        }
        if (null !== $result->writableHt && null !== $result->writableIndex) {
            \PHPCompiler\JIT\HashTableHelper::setAtIndex(
                $this->context,
                $result->writableHt,
                $result->writableIndex,
                $value
            );
            $this->syncDimWriteOrphanValueBox($result, $value);

            return;
        }
        if ($result->isArrayAccessWritableOffset) {
            if (
                \PHPCompiler\JIT\UserScriptAotEnv::isActive()
                && null !== $result->writableArrayAccessReceiver
                && null !== $result->writableArrayAccessKey
            ) {
                $sxeSet = $this->context->extensionLowering->tryOffsetSet(
                    $this->context,
                    $result->writableArrayAccessReceiver,
                    $result->writableArrayAccessKey,
                    $value
                );
                if (null !== $sxeSet) {
                    return;
                }
            }
            \PHPCompiler\JIT\ArrayAccessHelper::assignWritableOffset($this->context, $result, $value);

            return;
        }
        if (
            $result->kind === Variable::KIND_VALUE
            && $result->type === Variable::TYPE_STRING
            && \PHPCompiler\JIT\StringOffsetHelper::isWritableCharOffsetLvalue($result, $this->context)
        ) {
            \PHPCompiler\JIT\StringOffsetHelper::dimAssign($this->context, $result->value, $value);

            return;
        }
        if (
            Variable::TYPE_NATIVE_BOOL === $value->type
            && Variable::TYPE_STRING === $result->type
            && (Variable::KIND_VARIABLE === $result->kind || Variable::KIND_VALUE === $result->kind)
        ) {
            // && short-circuit false branch can target a phi slot still typed from a string dim fetch (#1492).
            $this->context->setVariableOp(
                $resultOp,
                new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_BOOL,
                    Variable::KIND_VALUE,
                    $this->context->helper->loadValue($value)
                )
            );

            return;
        }
        if (
            Variable::TYPE_NATIVE_BOOL === $value->type
            && Variable::TYPE_NATIVE_BOOL === $result->type
            && Variable::KIND_VALUE === $result->kind
        ) {
            // defined() && CONST phi merge in bin/vm.php spine guard (#1492).
            $this->context->setVariableOp(
                $resultOp,
                new Variable(
                    $this->context,
                    Variable::TYPE_NATIVE_BOOL,
                    Variable::KIND_VALUE,
                    Variable::KIND_VALUE === $value->kind
                        ? $value->value
                        : $this->context->helper->loadValue($value)
                )
            );

            return;
        }
        if (null !== $result->valueBoxAliasPtr) {
            \PHPCompiler\JIT\JitValueBox::assignToPointer(
                $this->context,
                $result->valueBoxAliasPtr,
                $value
            );
            $this->preserveClosureInvokeMetadata($resultOp, $result, $value);
            $this->syncAssignRefHtEntriesFromShared($result);

            return;
        }
        if ($result->kind !== Variable::KIND_VARIABLE) {
            if ($this->tryAssignScriptGlobalFirstBinding($resultOp, $value)) {
                return;
            }
            $globalTarget = $this->resolveScriptGlobalAssignTarget($resultOp, $result);
            if (null !== $globalTarget) {
                $globalPtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $globalTarget);
                \PHPCompiler\JIT\JitValueBox::assignToPointer(
                    $this->context,
                    $globalPtr,
                    $value
                );
                \PHPCompiler\JIT\JitValueBox::publishAfterWrite($this->context, $globalPtr);
                $this->preserveClosureInvokeMetadata($resultOp, $globalTarget, $value);
                $this->invalidateScriptGlobalCompileTimeMetadata($globalTarget);
                $this->syncCompileTimeString($globalTarget, $value, false);
                $this->syncCompileTimeFloat($globalTarget, $value, false);
                $this->syncCompileTimeBcmathNumber($globalTarget, $value, false);
                $this->syncCompileTimeDomTagName($globalTarget, $value, false);
                $this->syncCompileTimeDatePeriod($globalTarget, $value, false);
                $classTag = $value->classUserType ?? null;
                if (is_string($classTag) && '' !== $classTag) {
                    $globalTarget->classUserType = $classTag;
                    $globalTarget->isNullConstant = false;
                    $resultOp->type = new Type(Type::TYPE_OBJECT, [], $classTag);
                }
                $this->context->setVariableOp($resultOp, $globalTarget);
                $globalName = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null !== $globalName && '' !== $globalName) {
                    $this->context->bindVariableByName(
                        $this->context->resolveRefAliasName($globalName),
                        $globalTarget
                    );
                }
                $this->markScopeVariableAssignedIfTracked($resultOp, $globalTarget);

                return;
            }
            if (Variable::TYPE_STRING === $result->type && Variable::KIND_VALUE === $result->kind) {
                $llvmFunc = $this->context->builder->getInsertBlock()->getParent();
                $slot = \PHPCompiler\JIT\BasicBlockHelper::entryAllocaForFunction(
                    $this->context,
                    $llvmFunc,
                    $this->context->getTypeFromString('__string__*')
                );
                $promoted = new Variable(
                    $this->context,
                    Variable::TYPE_STRING,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $promoted->initialize();
                if (null !== $result->value) {
                    $this->context->builder->store($result->value, $slot);
                    $promoted->addref();
                }
                $this->context->setVariableOp($resultOp, $promoted);
                $result = $promoted;
            } elseif (
                Variable::TYPE_OBJECT === $result->type
                && Variable::KIND_VALUE === $result->kind
                && null !== $result->value
                && (
                    '__object__*' === $this->context->getStringFromType($result->value->typeOf())
                    || str_starts_with($this->context->getStringFromType($result->value->typeOf()), '__object__')
                )
            ) {
                // Nullable `?T $p` formals are KIND_VALUE `__object__*` SSA params. Assigning
                // `$p = $p ?? new T` must promote to an alloca — SSA params are immutable, so
                // keeping KIND_VALUE left parent::__construct / ClassParamCheck reading the
                // original null arg (Slim AppFactory::create, #36382).
                $llvmFunc = $this->context->builder->getInsertBlock()->getParent();
                $objPtrTy = $this->context->getTypeFromString('__object__*');
                $slot = \PHPCompiler\JIT\BasicBlockHelper::entryAllocaForFunction(
                    $this->context,
                    $llvmFunc,
                    $objPtrTy
                );
                $this->context->builder->store($result->value, $slot);
                $promoted = new Variable(
                    $this->context,
                    Variable::TYPE_OBJECT,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $promoted->classUserType = $result->classUserType;
                $promoted->isNullConstant = $result->isNullConstant;
                $this->context->setVariableOp($resultOp, $promoted);
                $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName(
                        $this->context->resolveRefAliasName($resolved),
                        $promoted
                    );
                }
                if (null !== $this->context->jitCurrentBlock) {
                    $this->context->recordScopeSlotObjectMirrorLlvm(
                        $this->context->jitCurrentBlock,
                        $resultOp,
                        $promoted
                    );
                }
                $result = $promoted;
            } elseif (
                Variable::KIND_VALUE === $result->kind
                && in_array($result->type, [
                    Variable::TYPE_NATIVE_BOOL,
                    Variable::TYPE_NATIVE_LONG,
                    Variable::TYPE_NATIVE_DOUBLE,
                ], true)
            ) {
                // Class static property lvalues must store via module globals + init flag,
                // not a promoted stack slot (#20877, #31965).
                if (null !== $result->staticPropertyGlobal && null !== $result->staticPropertyType) {
                    if (
                        !\PHPCompiler\JIT\AsymmetricVisibilityGuard::emitBeforeStaticPropertyStore(
                            $this->context,
                            $this,
                            $result,
                            $this->context->jitEnclosingBlock
                        )
                        && !\PHPCompiler\JIT\PropertyHookDispatch::emitStaticSetHookIfNeeded(
                            $this->context,
                            $result,
                            $value,
                            $this->context->jitEnclosingBlock,
                            $this
                        )
                    ) {
                        $this->context->type->object->staticPropertyStore(
                            $result->staticPropertyGlobal,
                            $value,
                            $result->staticPropertyType,
                            $result->staticPropertyInitGlobal
                        );
                    }

                    return;
                }
                $llvmFunc = $this->context->builder->getInsertBlock()->getParent();
                $slot = \PHPCompiler\JIT\BasicBlockHelper::entryAllocaForFunction(
                    $this->context,
                    $llvmFunc,
                    $this->context->getTypeFromString(Variable::getStringType($result->type))
                );
                $promoted = new Variable(
                    $this->context,
                    $result->type,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                if (null !== $result->value) {
                    $this->context->builder->store($result->value, $slot);
                    $promoted->addref();
                }
                $this->context->setVariableOp($resultOp, $promoted);
                $result = $promoted;
            } elseif (Variable::KIND_VALUE === $result->kind) {
                // TYPE_NULL / TYPE_VALUE KIND_VALUE temps that later receive an assign —
                // nested array literals in TimezoneAbbreviationsData.php (#28998 / re-#16866).
                // Promote to a boxed KIND_VARIABLE slot (same shape as branchMergeTarget).
                $destTy = null !== $result->value
                    ? $this->context->getStringFromType($result->value->typeOf())
                    : '';
                if ('__value__*' === $destTy) {
                    $slot = $result->value;
                } else {
                    $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                    if ('__value__' === $destTy && null !== $result->value) {
                        \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                            $this->context,
                            $slot,
                            \PHPCompiler\JIT\JitValueBox::pointer($this->context, $result->value)
                        );
                    } elseif (Variable::TYPE_NULL === $result->type) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__value__writeNull'),
                            \PHPCompiler\JIT\JitValueBox::pointer($this->context, $slot)
                        );
                    } elseif (Variable::TYPE_HASHTABLE === $result->type) {
                        $ptr = $this->context->helper->loadValue($result);
                        $this->context->builder->call(
                            $this->context->lookupFunction('__value__writeHashtable'),
                            \PHPCompiler\JIT\JitValueBox::pointer($this->context, $slot),
                            $ptr
                        );
                        $this->context->refcount->addref($ptr);
                    }
                }
                $promoted = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                if (Variable::TYPE_HASHTABLE === $result->type) {
                    $promoted->valueBoxHashtable = true;
                }
                $this->context->setVariableOp($resultOp, $promoted);
                // Named formals must follow the promoted alloca — otherwise later
                // getVariableFromOp resurrects the KIND_VALUE LLVM param (#36382).
                $resolvedPromo = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null !== $resolvedPromo && '' !== $resolvedPromo) {
                    $this->context->bindVariableByName(
                        $this->context->resolveRefAliasName($resolvedPromo),
                        $promoted
                    );
                }
                $result = $promoted;
            } else {
                throw new \LogicException('Cannot assign to a value');
            }
        }
        if (
            $branchMergeTarget
            && null === $result->objectPropertySlot
            && null === $result->staticPropertyGlobal
            && !$result->functionStaticGlobal
        ) {
            if (Variable::TYPE_VALUE !== $result->type || Variable::KIND_VARIABLE !== $result->kind) {
                $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                $this->context->setVariableOp(
                    $resultOp,
                    new Variable(
                        $this->context,
                        Variable::TYPE_VALUE,
                        Variable::KIND_VARIABLE,
                        $slot
                    )
                );
                $result = $this->context->getVariableFromOp($resultOp);
            }
            \PHPCompiler\JIT\JitValueBox::assignToPointer(
                $this->context,
                \PHPCompiler\JIT\JitValueBox::pointer($this->context, $result->value),
                $value
            );
            // Value-box locals must keep DateTimeZone/DateTime fold stamps (#29732).
            $this->syncCompileTimeString($result, $value, false);
            $this->syncCompileTimeFloat($result, $value, false);
            $this->syncCompileTimeBcmathNumber($result, $value, false);
            $this->syncCompileTimeDomTagName($result, $value, false);
            $this->syncCompileTimeDatePeriod($result, $value, false);
            $result->compileTimeLong = $value->compileTimeLong ?? $result->compileTimeLong;
            $this->noteDateTimeZoneLocal($resultOp, $value);
            $this->noteDateTimeLocal($resultOp, $value);
            $this->preserveClosureInvokeMetadata($resultOp, $result, $value);
            $this->syncAssignRefHtEntriesFromShared($result);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $result);
            }
            // ??= FETCH_OBJ_W: this path used to return without objectPropertySlot, so the
            // later store wrote only the merge alloca (#33748 / re-#32880).
            if (
                $this->context->retainCoalesceInstancePropertyLvalue
                && null !== $value->objectPropertySlot
            ) {
                $this->copyObjectPropertyBacking($result, $value);
            }
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        }
        if (
            $value->type === $result->type
            && !($branchMergeTarget && Variable::TYPE_VALUE === $result->type)
        ) {
            if (
                Variable::TYPE_VALUE === $value->type
                && Variable::KIND_VALUE === $value->kind
                && '__value__' === $this->context->getStringFromType($value->value->typeOf())
                && '__value__' === $this->context->getStringFromType($result->value->typeOf())
                && null === $result->valueBoxAliasPtr
                && !$result->borrowedValueEntry
            ) {
                if (!$result->includeBinding) {
                    $result->free();
                }
                \PHPCompiler\JIT\BasicBlockHelper::repositionToLastOpenIfInsertLost($this->context);
                if (!\PHPCompiler\JIT\BasicBlockHelper::unsealAndContinue($this->context)) {
                    \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'assign_same_type_cont');
                }
                $this->context->builder->store($value->value, $result->value);
                $this->maybeCopyObjectPropertyBacking($result, $value, $force);
                if (null === $result->objectPropertySlot) {
                    $result->addref();
                }
                $this->copyValueBoxJitFlags($result, $value, $force);
                $result->compileTimeConstantName = $value->compileTimeConstantName;
                $result->compileTimeEnumCase = $value->compileTimeEnumCase;
                $this->syncCompileTimeString($result, $value, $force);
                $this->syncCompileTimeFloat($result, $value, $force);
                $this->syncCompileTimeBcmathNumber($result, $value, $force);
                $this->syncCompileTimeDomTagName($result, $value, $force);
                $this->syncCompileTimeDatePeriod($result, $value, $force);
                $this->noteDateTimeZoneLocal($resultOp, $value);
                $this->noteDateTimeLocal($resultOp, $value);
                $this->preserveClosureInvokeMetadata($resultOp, $result, $value);
                $this->markScopeVariableAssignedIfTracked($resultOp, $result);

                return;
            }
            if (null !== $result->staticPropertyGlobal && null !== $result->staticPropertyType) {
                if (
                    !\PHPCompiler\JIT\AsymmetricVisibilityGuard::emitBeforeStaticPropertyStore(
                        $this->context,
                        $this,
                        $result,
                        $this->context->jitEnclosingBlock
                    )
                    && !\PHPCompiler\JIT\PropertyHookDispatch::emitStaticSetHookIfNeeded(
                        $this->context,
                        $result,
                        $value,
                        $this->context->jitEnclosingBlock,
                        $this
                    )
                ) {
                    $this->context->type->object->staticPropertyStore(
                        $result->staticPropertyGlobal,
                        $value,
                        $result->staticPropertyType,
                        $result->staticPropertyInitGlobal
                    );
                }

                return;
            }
            if (!$result->includeBinding) {
                // copyBetweenPointers / foreach value fetch may branchToFreshContinue and
                // seal the insert BB; delref here must not emit parentless IR (#26783 / #26784).
                \PHPCompiler\JIT\BasicBlockHelper::ensureOpenInsertBlock($this->context, 'assign_same_type_free_cont');
                if ('__object__**' === $this->context->getStringFromType($result->value->typeOf())) {
                    $this->freeObjectMirrorUnlessNull($result);
                } else {
                    $result->free();
                }
            }
            if ($value->type & Variable::IS_NATIVE_ARRAY || Variable::TYPE_HASHTABLE === $value->type) {
                $result->nextFreeElement = $value->nextFreeElement;
            }
            if (Variable::TYPE_VALUE === $value->type) {
                $destLlvm = $result->value->typeOf();
                $destTy = $this->context->getStringFromType($destLlvm);
                if ('__value__' === $destTy || '__value__*' === $destTy) {
                    $destPointsAtStruct = '__value__' === $destTy;
                    if (
                        '__value__*' === $destTy
                        && \PHPLLVM\Type::KIND_POINTER === $destLlvm->getKind()
                        && '__value__' === $this->context->getStringFromType($destLlvm->getElementType())
                    ) {
                        $destPointsAtStruct = true;
                    }
                    if ($destPointsAtStruct) {
                        \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                            $this->context,
                            $result->value,
                            $this->valueBoxPointer($value)
                        );
                    } else {
                        $this->context->builder->store(
                            $this->valueBoxPointer($value),
                            $result->value
                        );
                    }
                    $this->maybeCopyObjectPropertyBacking($result, $value, $force);
                    if (null === $result->objectPropertySlot) {
                        $result->addref();
                    }
                    $this->copyValueBoxJitFlags($result, $value, $force);
                    $result->compileTimeConstantName = $value->compileTimeConstantName;
                    $result->compileTimeEnumCase = $value->compileTimeEnumCase;
                    $this->syncCompileTimeString($result, $value, $force);
                    $this->syncCompileTimeFloat($result, $value, $force);
                    $this->syncCompileTimeBcmathNumber($result, $value, $force);
                    $this->syncCompileTimeDomTagName($result, $value, $force);
                    $this->syncCompileTimeDatePeriod($result, $value, $force);
                    $this->noteDateTimeZoneLocal($resultOp, $value);
                    $this->noteDateTimeLocal($resultOp, $value);
                    $this->markScopeVariableAssignedIfTracked($resultOp, $result);

                    return;
                }
            }
            $toStore = $this->context->helper->loadValue($value);
            // Refuse typed-mismatched stores (e.g. i64 into %__string__**) — LLVM build
            // accepts them; bitcode parse rejects and edit-scaffold dies (#36387).
            if (null !== $result->value && null !== $toStore) {
                $destTy = $this->context->getStringFromType($result->value->typeOf());
                $srcTy = $this->context->getStringFromType($toStore->typeOf());
                $destIsStringSlot = '__string__**' === $destTy
                    || (str_contains($destTy, '__string__') && str_ends_with($destTy, '**'));
                $srcIsInt = 'int64' === $srcTy || 'i64' === $srcTy;
                if ($destIsStringSlot && $srcIsInt) {
                    $fromLong = $this->context->module->getNamedFunction('__string__fromLong');
                    if ($fromLong instanceof \PHPLLVM\Value\Function_) {
                        $strPtr = $this->context->builder->call($fromLong, $toStore);
                        $this->context->builder->store($strPtr, $result->value);
                        $this->markScopeVariableAssignedIfTracked($resultOp, $result);

                        return;
                    }
                    throw new \LogicException(
                        "assignOperand: refusing store {$srcTy} into {$destTy} (#36387)"
                    );
                }
                // Same PHP type can still be a native packed array whose loadValue is
                // an LLVM array aggregate (`[N x %__string__*]`) while the destination
                // slot was promoted to a `__value__*` box (IncludeHelper / branch merge).
                // getStringFromType() maps arrays to "unknown" / uniquified `__value__.N`
                // as `unknown*` — key off LLVM kinds, not type-name strings (#36382).
                $srcKind = $toStore->typeOf()->getKind();
                $destLlvm = $result->value->typeOf();
                $destIsArrayPtr = \PHPLLVM\Type::KIND_POINTER === $destLlvm->getKind()
                    && \PHPLLVM\Type::KIND_ARRAY === $destLlvm->getElementType()->getKind();
                if (\PHPLLVM\Type::KIND_ARRAY === $srcKind && !$destIsArrayPtr) {
                    if (0 === ($value->type & Variable::IS_NATIVE_ARRAY)) {
                        throw new \LogicException(
                            "assignOperand: refusing store LLVM array aggregate into {$destTy} (#36382)"
                        );
                    }
                    $ht = \PHPCompiler\JIT\HashTableHelper::materializeNativeArrayForCall(
                        $this->context,
                        $value
                    );
                    $destPtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable(
                        $this->context,
                        $result
                    );
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeHashtable'),
                        $destPtr,
                        $ht
                    );
                    $this->context->refcount->delref(
                        $this->context->builder->pointerCast(
                            $ht,
                            $this->context->getTypeFromString('__ref__virtual*')
                        )
                    );
                    $result->valueBoxHashtable = true;
                    $this->markScopeVariableAssignedIfTracked($resultOp, $result);

                    return;
                }
            }
            $this->context->builder->store(
                $toStore,
                $result->value
            );
            $this->maybeCopyObjectPropertyBacking($result, $value, $force);
            // User-function NEW rvalues (KIND_VALUE) already own rc=1; addref into the
            // result temp would leave a root past ZEND_ASSIGN + return (#36245 scope_exit).
            // {main} script-globals use the value-box path and still need the addref.
            // INIT_ARRAY native temps already hold rc=1 — move into the CV (#36388).
            // Owning `__string__*` FUNCCALL temps (str_repeat / typed `:string` returns)
            // likewise — addref+keep-temp left rc≥2 so unset never destroyed (#36388).
            $skipAddrefForHashtableMove = $value->ephemeralArrayTemp
                && Variable::TYPE_HASHTABLE === $value->type
                && Variable::KIND_VARIABLE === $value->kind
                && $value !== $result
                && null !== $value->value;
            $skipAddrefForStringMove = $value->ephemeralStringTemp
                && Variable::TYPE_STRING === $value->type
                && Variable::KIND_VARIABLE === $value->kind
                && $value !== $result
                && null !== $value->value;
            $skipAddrefForNewRvalue = Variable::KIND_VALUE === $value->kind
                && null !== $this->context->jitEnclosingBlock
                && null !== $this->context->jitEnclosingBlock->func
                && '{main}' !== $this->context->jitEnclosingBlock->func->name;
            if (
                null === $result->objectPropertySlot
                && !$skipAddrefForNewRvalue
                && !$skipAddrefForHashtableMove
                && !$skipAddrefForStringMove
            ) {
                $result->addref();
            }
            if ($skipAddrefForHashtableMove) {
                $this->context->builder->store(
                    $this->context->getTypeFromString('__hashtable__*')->constNull(),
                    $value->value
                );
                $value->ephemeralArrayTemp = false;
                $result->ephemeralArrayTemp = false;
            }
            if ($skipAddrefForStringMove) {
                $this->context->builder->store(
                    $this->context->getTypeFromString('__string__*')->constNull(),
                    $value->value
                );
                $value->ephemeralStringTemp = false;
                $result->ephemeralStringTemp = false;
            }
            $this->copyValueBoxJitFlags($result, $value, $force);
            $result->compileTimeConstantName = $value->compileTimeConstantName;
            $result->compileTimeEnumCase = $value->compileTimeEnumCase;
            $this->syncCompileTimeString($result, $value, $force);
            $this->syncCompileTimeFloat($result, $value, $force);
            $this->syncCompileTimeBcmathNumber($result, $value, $force);
            $this->syncCompileTimeDomTagName($result, $value, $force);
            $this->syncCompileTimeDatePeriod($result, $value, $force);
            $this->noteDateTimeZoneLocal($resultOp, $value);
            $this->noteDateTimeLocal($resultOp, $value);
            $this->preserveClosureInvokeMetadata($resultOp, $result, $value);
            if ($value->isJitGenerator) {
                $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $result);
                }
            }
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        } elseif ($result->type === Variable::TYPE_VALUE) {
            // wrap
            $this->maybeCopyObjectPropertyBacking($result, $value, $force);
            $valueRef = $result->value;
            $valueFrom = $value->value;
            if ($value->type & Variable::IS_NATIVE_ARRAY) {
                // materialize addrefs to rc=1; writeHashtable → rc=2. Delref the materialize
                // claim so unset reaches dtor (#36388 packed `$a = [$i]`). promoteNativeArray
                // (usort by-ref) keeps #36484 no-delref — different ownership shape.
                $ht = \PHPCompiler\JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $value);
                $destPtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $result);
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeHashtable'),
                    $destPtr,
                    $ht
                );
                $this->context->refcount->delref(
                    $this->context->builder->pointerCast(
                        $ht,
                        $this->context->getTypeFromString('__ref__virtual*')
                    )
                );
                $result->valueBoxHashtable = true;

                return;
            }
            switch ($value->type) {
                case Variable::TYPE_NULL:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeNull') , 
                    $valueRef
                    
                );
                    // Null → VALUE box is always a compile-time null for builtin soft-null (#22680).
                    $result->isNullConstant = true;
                    $this->markScopeVariableAssignedIfTracked($resultOp, $result);

                    return;
                case Variable::TYPE_NATIVE_LONG:
                    if (null !== $result->writableHt && null !== $result->writableObjectKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setObjectKeyLong'),
                            $result->writableHt,
                            $result->writableObjectKey,
                            $this->context->helper->loadValue($value)
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyLong'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $this->context->helper->loadValue($value)
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableIndex) {
                        \PHPCompiler\JIT\HashTableHelper::setAtIndex(
                            $this->context,
                            $result->writableHt,
                            $result->writableIndex,
                            $value
                        );

                        return;
                    }
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeLong') , 
                    $valueRef
                    , $this->context->helper->loadValue($value)
                    
                );
                    $result->compileTimeConstantName = $value->compileTimeConstantName;
                    $result->compileTimeEnumCase = $value->compileTimeEnumCase;
                    // Keep scalar immediates across value-box assign (#23427).
                    $result->compileTimeLong = $value->compileTimeLong;
                    $this->markScopeVariableAssignedIfTracked($resultOp, $result);

                    return;
                case Variable::TYPE_NATIVE_DOUBLE:
                    $this->context->builder->call(
                    $this->context->lookupFunction('__value__writeDouble') , 
                    $valueRef
                    , $this->context->helper->loadValue($value)
                    
                );
                    $this->syncCompileTimeFloat($result, $value, $force);
                    $this->syncCompileTimeBcmathNumber($result, $value, $force);
                    $this->syncCompileTimeDomTagName($result, $value, $force);
                    $this->syncCompileTimeDatePeriod($result, $value, $force);
                    $this->markScopeVariableAssignedIfTracked($resultOp, $result);

                    return;
                case Variable::TYPE_NATIVE_BOOL:
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyBool'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $this->context->builder->truncOrBitCast(
                                $this->context->helper->loadValue($value),
                                $this->context->getTypeFromString('int1')
                            )
                        );

                        return;
                    }
                    \PHPCompiler\JIT\JitValueBox::writeBool(
                        $this->context,
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );
                    // Keep scalar immediates across value-box assign (#23427).
                    $result->compileTimeLong = $value->compileTimeLong;
                    $this->markScopeVariableAssignedIfTracked($resultOp, $result);

                    return;
                case Variable::TYPE_STRING:
                    $str = \PHPCompiler\JIT\JitStringArg::lowerDominating($this->context, $value, 'value box write');
                    // Owning `__string__*` FUNCCALL temps: move into the value box without
                    // `__string__separate` (always-copy) which would leave the temp's string
                    // alive at rc≥1 after freeDeadVariables — unset never frees (#36388).
                    $moveEphemeralString = $value->ephemeralStringTemp
                        && Variable::TYPE_STRING === $value->type
                        && Variable::KIND_VARIABLE === $value->kind
                        && $value !== $result
                        && null !== $value->value;
                    $owned = $moveEphemeralString
                        ? $str
                        : $this->context->builder->call(
                            $this->context->lookupFunction('__string__separate'),
                            $str
                        );
                    if (null !== $result->writableHt && null !== $result->writableIndex) {
                        \PHPCompiler\JIT\HashTableHelper::setAtIndex(
                            $this->context,
                            $result->writableHt,
                            $result->writableIndex,
                            $value
                        );

                        return;
                    }
                    if (null !== $result->writableHt && null !== $result->writableStringKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setStringKeyString'),
                            $result->writableHt,
                            $result->writableStringKey,
                            $owned
                        );

                        return;
                    }
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeString'),
                        $valueRef,
                        $owned
                    );
                    if ($moveEphemeralString) {
                        $this->context->builder->store(
                            $this->context->getTypeFromString('__string__*')->constNull(),
                            $value->value
                        );
                        $value->ephemeralStringTemp = false;
                    }
                    $this->syncCompileTimeString($result, $value, $force);
                    // Nullsafe/?? null arms set isNullConstant on the shared merge Variable at
                    // compile time; a later fetch-arm string write must clear it or echo/?? see
                    // an empty null constant despite the IR string (#34024).
                    $result->isNullConstant = false;

                    return;
                case Variable::TYPE_HASHTABLE:
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeHashtable'),
                        $valueRef,
                        $this->context->helper->loadValue($value)
                    );
                    $result->valueBoxHashtable = true;

                    return;
                case Variable::TYPE_OBJECT:
                    $objVal = $this->context->helper->loadValue($value);
                    if (null !== $result->writableHt && null !== $result->writableObjectKey) {
                        $this->context->builder->call(
                            $this->context->lookupFunction('__hashtable__setObjectKeyObject'),
                            $result->writableHt,
                            $result->writableObjectKey,
                            $objVal
                        );

                        return;
                    }
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__writeObject'),
                        $valueRef,
                        $objVal
                    );
                    $this->preserveClosureInvokeMetadata($resultOp, $result, $value);
                    $result->compileTimeConstantName = $value->compileTimeConstantName;
                    $result->compileTimeEnumCase = $value->compileTimeEnumCase;
                    $result->isNullConstant = false;
                    $this->syncCompileTimeString($result, $value, $force);
                    $this->syncCompileTimeBcmathNumber($result, $value, $force);
                    $this->syncCompileTimeDomTagName($result, $value, $force);
                    $this->syncCompileTimeDatePeriod($result, $value, $force);
                    $objectUserType = $value->classUserType ?? null;
                    if (is_string($objectUserType) && '' !== $objectUserType) {
                        $resultOp->type = new Type(Type::TYPE_OBJECT, [], $objectUserType);
                    }
                    $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                    if (null !== $resolved && '' !== $resolved) {
                        $this->context->bindVariableByName(
                            $this->context->resolveRefAliasName($resolved),
                            $result
                        );
                    }

                    return;
                case Variable::TYPE_VALUE:
                    \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                        $this->context,
                        $valueRef,
                        $this->valueBoxPointer($value)
                    );
                    $this->copyValueBoxJitFlags($result, $value, $force);
                    $this->preserveClosureInvokeMetadata($resultOp, $result, $value);
                    $this->recordListUnpackAssignSlot($resultOp, $result);

                    return;
                default:
                    if ($value->type & Variable::IS_NATIVE_ARRAY) {
                        // materialize addrefs to rc=1; writeHashtable → rc=2; delref → sole owner (#36388).
                        $ht = \PHPCompiler\JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $value);
                        $destPtr = \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $result);
                        $this->context->builder->call(
                            $this->context->lookupFunction('__value__writeHashtable'),
                            $destPtr,
                            $ht
                        );
                        $this->context->refcount->delref(
                            $this->context->builder->pointerCast(
                                $ht,
                                $this->context->getTypeFromString('__ref__virtual*')
                            )
                        );
                        $result->valueBoxHashtable = true;

                        return;
                    }
                    throw new \LogicException("Source type: {$value->type}");
            }
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_VALUE === $value->type) {
            // Untyped locals must widen on float (and other) assigns — truncating via
            // fpToSi made `$s = 0; $s = 0.028` store 0 and broke mandelbrot AOT (#23471).
            if ($this->nativeLongWidenAssignIsNativeDouble($value)) {
                $this->promoteNativeLongLvalueToNativeDouble($resultOp, $result, $value);
            } else {
                $this->promoteNativeLongLvalueToValueBox($resultOp, $result, $value);
            }

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_NATIVE_DOUBLE === $value->type) {
            $this->promoteNativeLongLvalueToNativeDouble($resultOp, $result, $value);

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_NATIVE_BOOL === $value->type) {
            $result->free();
            $boolVal = $this->context->helper->loadValue($value);
            $long = $this->context->builder->zExt($boolVal, $this->context->getTypeFromString('int64'));
            $this->context->builder->store($long, $result->value);
            $result->addref();
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_LONG && Variable::TYPE_STRING === $value->type) {
            // Guard: never store i64 into a non-i64* slot. Mis-typed static string props
            // (value=@sp_* string**, type=NATIVE_LONG) produced `store i64, %__string__**`
            // which LLVM accepts at build time but bitcode parse rejects — blocking
            // edit-scaffold module.bc round-trip (#36387 / OutputRewriteVarsJitHelper::add).
            $destTy = null !== $result->value
                ? $this->context->getStringFromType($result->value->typeOf())
                : '';
            if ('__string__**' === $destTy || (str_contains($destTy, '__string__') && str_ends_with($destTy, '**'))) {
                $strPtr = $this->context->helper->loadValue($value);
                if (Variable::KIND_VARIABLE === $result->kind || null !== $result->staticPropertyGlobal) {
                    $old = $this->context->builder->load($result->value);
                    $this->context->builder->store($strPtr, $result->value);
                    // Keep the new string alive; delref old if it was a string pointer.
                    $this->context->builder->call(
                        $this->context->lookupFunction('__ref__addref'),
                        $this->context->builder->pointerCast(
                            $strPtr,
                            $this->context->getTypeFromString('__ref__virtual*')
                        )
                    );
                    $oldIsNull = $this->context->builder->icmp(
                        \PHPLLVM\Builder::INT_EQ,
                        $old,
                        $old->typeOf()->constNull()
                    );
                    $skipOld = $this->context->builder->getInsertBlock()->appendBasicBlock('str_assign_skip_old');
                    $doOld = $this->context->builder->getInsertBlock()->appendBasicBlock('str_assign_delref_old');
                    $done = $this->context->builder->getInsertBlock()->appendBasicBlock('str_assign_done');
                    $this->context->builder->branchIf($oldIsNull, $skipOld, $doOld);
                    $this->context->builder->positionAtEnd($doOld);
                    $this->context->builder->call(
                        $this->context->lookupFunction('__ref__delref'),
                        $this->context->builder->pointerCast(
                            $old,
                            $this->context->getTypeFromString('__ref__virtual*')
                        )
                    );
                    $this->context->builder->branch($done);
                    $this->context->builder->positionAtEnd($skipOld);
                    $this->context->builder->branch($done);
                    $this->context->builder->positionAtEnd($done);
                } else {
                    $this->context->builder->store($strPtr, $result->value);
                }
                $result->type = Variable::TYPE_STRING;
                $this->markScopeVariableAssignedIfTracked($resultOp, $result);

                return;
            }
            $result->free();
            $long = \PHPCompiler\JIT\JitLongArg::lowerStringValue($this->context, $this->context->helper->loadValue($value));
            $this->context->builder->store($long, $result->value);
            $result->addref();
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_DOUBLE && Variable::TYPE_NATIVE_LONG === $value->type) {
            $result->free();
            $long = $this->context->helper->loadValue($value);
            $fp = $this->context->builder->siToFp($long, $this->context->getTypeFromString('double'));
            $this->context->builder->store($fp, $result->value);
            $result->addref();
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        } elseif ($result->type === Variable::TYPE_NATIVE_DOUBLE && Variable::TYPE_VALUE === $value->type) {
            // Loop-fused `$zr2 = $zr * $zr` (ASSIGN elided; MUL writes named CV) unboxes a
            // vbox product into a preallocated native-double slot — must flip the assigned
            // flag or echo warns (#36405 respin / #36386 mandelbrot).
            $fp = $this->unboxValueToNativeDouble($value);
            $result->free();
            $this->context->builder->store($fp, $result->value);
            $result->addref();
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        } elseif (Variable::TYPE_VALUE === $result->type && Variable::TYPE_VALUE === $value->type) {
            if (Variable::KIND_VARIABLE !== $result->kind) {
                $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                $boxed = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $this->context->setVariableOp($resultOp, $boxed);
                $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $boxed);
                }
                $result = $boxed;
            }
            \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                $this->context,
                $result->value,
                $this->valueBoxPointer($value)
            );
            $this->copyValueBoxJitFlags($result, $value, $force);
            $result->compileTimeConstantName = $value->compileTimeConstantName;
            $result->compileTimeEnumCase = $value->compileTimeEnumCase;
            $this->syncCompileTimeString($result, $value, $force);
            $this->recordListUnpackAssignSlot($resultOp, $result);
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        } elseif (Variable::TYPE_HASHTABLE === $result->type && Variable::TYPE_VALUE === $value->type) {
            // ini_get_all() and similar builtins return array|false as __value__; keep the box
            // so strict comparisons against false use JitValueCompare (issue #3205, #848).
            // Rebind named formals onto the demoted VALUE slot — peer scalar←HASHTABLE (#36397)
            // and FastRoute `$options = [… ?? …]` (#36382).
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                $this->context,
                $slot,
                $this->valueBoxPointer($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $this->copyValueBoxJitFlags($result, $value, $force);
            $result->valueBoxHashtable = $result->valueBoxHashtable || $value->valueBoxHashtable;
            $result->compileTimeConstantName = $value->compileTimeConstantName;
            $result->compileTimeEnumCase = $value->compileTimeEnumCase;
            $this->syncCompileTimeString($result, $value, $force);
            $result->addref();
            $this->context->setVariableOp($resultOp, $result);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($resolved),
                    $result
                );
            }
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        } elseif (
            Variable::TYPE_HASHTABLE === $result->type
            && 0 !== ($value->type & Variable::IS_NATIVE_ARRAY)
        ) {
            // Packed native array (e.g. string[]) → array-typed local. Parsedown `$Line = [...]`
            // after `$this->$methodName()` NestedJIT (#36380). Peer VALUE←NATIVE_ARRAY above
            // and HASHTABLE←VALUE demotion (#3205).
            $ht = \PHPCompiler\JIT\HashTableHelper::materializeNativeArrayForCall($this->context, $value);
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                \PHPCompiler\JIT\JitValueBox::pointer($this->context, $slot),
                $ht
            );
            $this->context->refcount->delref(
                $this->context->builder->pointerCast(
                    $ht,
                    $this->context->getTypeFromString('__ref__virtual*')
                )
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->valueBoxHashtable = true;
            $result->nextFreeElement = $value->nextFreeElement;
            $this->context->setVariableOp($resultOp, $result);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($resolved),
                    $result
                );
            }
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        } elseif (
            0 !== ($result->type & Variable::IS_NATIVE_ARRAY)
            && Variable::TYPE_VALUE === $value->type
        ) {
            // phpdoc list<string> / string[] locals are native __string__*[]; many call
            // results (array_merge, glob, getenv parsing) arrive as TYPE_VALUE boxes.
            // Demote the destination like HASHTABLE←VALUE (#36387 HelperRuntimeCache).
            // Assoc INIT_ARRAY into a string[]-inferred formal must keep the VALUE box
            // visible to later FETCH_DIM (#36382 FastRoute options coalesce).
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                $this->context,
                $slot,
                $this->valueBoxPointer($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $this->copyValueBoxJitFlags($result, $value, $force);
            $result->valueBoxHashtable = $result->valueBoxHashtable || $value->valueBoxHashtable;
            $result->compileTimeConstantName = $value->compileTimeConstantName;
            $result->compileTimeEnumCase = $value->compileTimeEnumCase;
            $this->syncCompileTimeString($result, $value, $force);
            $result->addref();
            $this->context->setVariableOp($resultOp, $result);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($resolved),
                    $result
                );
            }
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        } elseif (
            0 !== ($result->type & Variable::IS_NATIVE_ARRAY)
            && Variable::TYPE_HASHTABLE === $value->type
        ) {
            // Typed list<string> local ← INIT_ARRAY / hashtable temp (#36387).
            // Do not promoteNativeArray on the destination — it may be uninitialized;
            // demote the slot and move the hashtable like VALUE←HASHTABLE.
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            $htPtr = $this->context->helper->loadValue($value);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                \PHPCompiler\JIT\JitValueBox::pointer($this->context, $slot),
                $htPtr
            );
            if ($value->ephemeralArrayTemp) {
                $this->context->builder->store(
                    $this->context->getTypeFromString('__hashtable__*')->constNull(),
                    $value->value
                );
                $value->ephemeralArrayTemp = false;
            }
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->valueBoxHashtable = true;
            $this->context->setVariableOp($resultOp, $result);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($resolved),
                    $result
                );
            }
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);

            return;
        } elseif (
            (
                Variable::TYPE_NATIVE_LONG === $result->type
                || Variable::TYPE_NATIVE_BOOL === $result->type
                || Variable::TYPE_NATIVE_DOUBLE === $result->type
            )
            && Variable::TYPE_HASHTABLE === $value->type
        ) {
            // AssignOp-fused `$a += […]`: PLUS result is HASHTABLE but the SSA CV for `$a`
            // may still be a scalar slot (php-types int[] / fresh SSA) (#36397).
            // php-src: Zend/zend_operators.c add_function array union → assign into CV.
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            $htPtr = $this->context->helper->loadValue($value);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                \PHPCompiler\JIT\JitValueBox::pointer($this->context, $slot),
                $htPtr
            );
            if ($value->ephemeralArrayTemp) {
                $this->context->builder->store(
                    $this->context->getTypeFromString('__hashtable__*')->constNull(),
                    $value->value
                );
                $value->ephemeralArrayTemp = false;
            }
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->valueBoxHashtable = true;
            $this->context->setVariableOp($resultOp, $result);
            $this->markScopeVariableAssignedIfTracked($resultOp, $result);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($resolved),
                    $result
                );
            }

            return;
        } elseif (Variable::TYPE_VALUE === $result->type && Variable::TYPE_HASHTABLE === $value->type) {
            // INIT_ARRAY temps already hold rc=1. writeHashtable addrefs for the box's
            // sole-owner claim — without releasing the temp that leaves rc=2 so unset
            // only drops to 1 and every loop iteration leaks (~288 B) (#36388).
            // php-src: zend_assign_to_variable moves the zval; no second ADDREF on the array.
            $htPtr = $this->context->helper->loadValue($value);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeHashtable'),
                $this->valueBoxPointer($result),
                $htPtr
            );
            if (
                $value->ephemeralArrayTemp
                && Variable::KIND_VARIABLE === $value->kind
                && null !== $value->value
                && $value !== $result
            ) {
                $this->context->refcount->delref(
                    $this->context->builder->pointerCast(
                        $htPtr,
                        $this->context->getTypeFromString('__ref__virtual*')
                    )
                );
                $this->context->builder->store(
                    $this->context->getTypeFromString('__hashtable__*')->constNull(),
                    $value->value
                );
                $value->ephemeralArrayTemp = false;
            }
            $result->valueBoxHashtable = true;
            $result->compileTimeEmptyArrayLiteral = $value->compileTimeEmptyArrayLiteral;

            return;
        } elseif (Variable::TYPE_STRING === $result->type && Variable::TYPE_VALUE === $value->type) {
            // getenv() and similar builtins return string|false as __value__; keep the box
            // so strict comparisons against false use JitValueCompare (issue #848).
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                $this->context,
                $slot,
                $this->valueBoxPointer($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $this->syncCompileTimeString($result, $value, $force);
            $result->addref();

            return;
        } elseif (Variable::TYPE_NATIVE_BOOL === $result->type && Variable::TYPE_VALUE === $value->type) {
            // InternalArgInfo may type an object-returning call as bool (XMLReader::XML,
            // DOMElement::removeAttributeNode). Keep the VALUE box when tagged (#28670, #32707).
            $objectUserType = $value->classUserType ?? '';
            if ('XMLReader' === $objectUserType || 'DOMAttr' === $objectUserType) {
                $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
                \PHPCompiler\JIT\JitValueBox::copyFromPointer(
                    $this->context,
                    $slot,
                    $this->valueBoxPointer($value)
                );
                $boxed = new Variable(
                    $this->context,
                    Variable::TYPE_VALUE,
                    Variable::KIND_VARIABLE,
                    $slot
                );
                $boxed->classUserType = $objectUserType;
                $this->context->setVariableOp($resultOp, $boxed);
                $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $boxed);
                }
                $resultOp->type = new Type(Type::TYPE_OBJECT, [], $objectUserType);

                return;
            }
            $boolVal = $this->context->castToBool($this->context->helper->loadValue($value));
            $result->free();
            $this->context->builder->store($boolVal, $result->value);
            $result->addref();

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_VALUE === $value->type) {
            if (
                Variable::KIND_VARIABLE === $value->kind
                && null === $value->objectPropertySlot
                && null === $value->staticPropertyGlobal
                && !$value->functionStaticGlobal
            ) {
                // `$y = $arr['o']` in a function: alias the HT value box instead of
                // extracting into an uninitialized __object__** slot (#31938).
                $this->context->setVariableOp($resultOp, $value);
                $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $value);
                }
                $this->markScopeVariableAssignedIfTracked($resultOp, $value);

                return;
            }
            $valuePtr = $this->valueBoxPointer($value);
            $map = $this->context->structFieldMap['__value__'];
            $typeByte = $this->context->builder->load(
                $this->context->builder->structGep($valuePtr, $map['type'])
            );
            $i8 = $this->context->getTypeFromString('int8');
            // Mask IS_REFCOUNTED — HT dim-fetch may store TYPE_OBJECT|0x80 (#21921, #31938).
            $kind = $this->context->builder->and($typeByte, $i8->constInt(0x7f, false));
            $isLong = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $kind,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            );
            $isBool = $this->context->builder->icmp(
                PHPLLVM\Builder::INT_EQ,
                $kind,
                $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
            );
            $isStreamHandle = $this->context->builder->bitwiseOr($isLong, $isBool);
            // One shared value-box for both IR arms. The previous split allocated a slot in
            // the promote arm then replaced `$result->value` with a second (NULL-init) slot
            // while building the handle arm — so `$d = $d->modify(...)` kept NULL (#33929).
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            $destPtr = \PHPCompiler\JIT\JitValueBox::pointer($this->context, $slot);
            $promoteBlock = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'assign_object_from_value');
            $handleBlock = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'assign_stream_handle_from_value');
            $doneBlock = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'assign_object_from_value_done');
            $this->context->builder->branchIf($isStreamHandle, $handleBlock, $promoteBlock);
            $this->context->builder->positionAtEnd($promoteBlock);
            // FETCH_DIM_R into an object-typed CV/temp: keep the boxed zval (#31938).
            \PHPCompiler\JIT\JitValueBox::copyFromPointer($this->context, $slot, $valuePtr);
            $this->context->builder->branch($doneBlock);

            $this->context->builder->positionAtEnd($handleBlock);
            $longBlock = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'assign_stream_handle_long');
            $boolBlock = \PHPCompiler\JIT\BasicBlockHelper::append($this->context, 'assign_stream_handle_bool');
            $this->context->builder->branchIf($isLong, $longBlock, $boolBlock);
            $this->context->builder->positionAtEnd($longBlock);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeLong'),
                $destPtr,
                $this->context->builder->call(
                    $this->context->lookupFunction('__value__readLong'),
                    $valuePtr
                )
            );
            $this->context->builder->branch($doneBlock);
            $this->context->builder->positionAtEnd($boolBlock);
            \PHPCompiler\JIT\JitValueBox::writeBool(
                $this->context,
                $slot,
                $this->context->builder->truncOrBitCast(
                    $this->context->builder->call(
                        $this->context->lookupFunction('__value__readLong'),
                        $valuePtr
                    ),
                    $this->context->getTypeFromString('int1')
                )
            );
            $this->context->builder->branch($doneBlock);

            $this->context->builder->positionAtEnd($doneBlock);
            // Do not free() — object-typed slots from fromOp are uninitialized __object__**
            // allocas; delref here segfaults under thin standalone AOT (#31938).
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $this->copyValueBoxJitFlags($result, $value, $force);
            $result->addref();
            $this->context->setVariableOp($resultOp, $result);
            $globalTarget = null;
            $assignResultName = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $assignResultName && '' !== $assignResultName) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($assignResultName),
                    $result
                );
                $globalTarget = $this->resolveScriptGlobalAssignTarget($resultOp, $result)
                    ?? $this->recoverScriptGlobalAssignLvalueBySlot($resultOp, $result);
            }
            if (null !== $globalTarget) {
                \PHPCompiler\JIT\JitValueBox::assignToPointer(
                    $this->context,
                    \PHPCompiler\JIT\JitValueBox::valuePtrFromVariable($this->context, $globalTarget),
                    $value
                );
                $this->preserveClosureInvokeMetadata($resultOp, $globalTarget, $value);
                $this->context->setVariableOp($resultOp, $globalTarget);
                $globalName = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null === $globalName || '' === $globalName) {
                    $block = $this->context->jitEnclosingBlock;
                    if (null !== $block) {
                        $slotNum = $block->slotForOperand($resultOp);
                        if (null !== $slotNum) {
                            foreach ($block->scopedOperands() as $scopeOp) {
                                if ($block->slotForOperand($scopeOp) !== $slotNum) {
                                    continue;
                                }
                                $scopeName = \PHPCompiler\JIT\OperandName::resolve($scopeOp);
                                if (null !== $scopeName && '' !== $scopeName) {
                                    $globalName = $scopeName;
                                    break;
                                }
                            }
                        }
                    }
                }
                if (null !== $globalName && '' !== $globalName) {
                    $this->context->bindVariableByName(
                        $this->context->resolveRefAliasName($globalName),
                        $globalTarget
                    );
                }
            }
            $this->noteDateTimeLocal($resultOp, $value);
            \PHPCompiler\JIT\BasicBlockHelper::branchToFreshContinue($this->context, 'after_assign_object_from_value_handle');

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_HASHTABLE === $value->type) {
            $ht = $this->context->helper->loadValue($value);
            $result->free();
            $this->context->builder->store(
                $this->context->builder->pointerCast(
                    $ht,
                    $this->context->getTypeFromString('__object__*')
                ),
                $result->value
            );
            $result->addref();

            return;
        } elseif (Variable::TYPE_HASHTABLE === $result->type && Variable::TYPE_OBJECT === $value->type) {
            if (null !== $result->writableHt && null !== $result->writableIndex) {
                \PHPCompiler\JIT\HashTableHelper::setAtIndex(
                    $this->context,
                    $result->writableHt,
                    $result->writableIndex,
                    $value
                );

                return;
            }
            // FCC `$b = $obj->m(...)` is CFG-typed as array, so `$b` is a hashtable slot.
            // Pointer-casting the Closure to `__hashtable__*` corrupts `$b()` under AOT
            // (inline `((new C)->m(...))(3)` works; assigned local aborts) (#28613).
            if (null !== $value->closureCall) {
                $result->free();
                $this->context->setVariableOp($resultOp, $value);
                $this->preserveClosureInvokeMetadata($resultOp, $value, $value);
                $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
                if (null !== $resolved && '' !== $resolved) {
                    $this->context->bindVariableByName($resolved, $value);
                }

                return;
            }
            $obj = $this->context->helper->loadValue($value);
            $result->free();
            $this->context->builder->store(
                $this->context->builder->pointerCast(
                    $obj,
                    $this->context->getTypeFromString('__hashtable__*')
                ),
                $result->value
            );
            $result->addref();

            return;
        } elseif (Variable::TYPE_NATIVE_BOOL === $result->type && Variable::TYPE_NATIVE_LONG === $value->type) {
            // Bool ++/-- promotes to int in a value box (#4727).
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            \PHPCompiler\JIT\JitValueBox::writeLong(
                $this->context,
                $slot,
                $this->context->helper->loadValue($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->addref();
            $this->context->setVariableOp($resultOp, $result);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $result);
            }

            return;
        } elseif (Variable::TYPE_STRING === $result->type && Variable::TYPE_NATIVE_BOOL === $value->type) {
            // JumpIf `&&` chains may reuse a string dim-fetch operand for a bool compare (#816, ns_func).
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            \PHPCompiler\JIT\JitValueBox::writeBool(
                $this->context,
                $slot,
                $this->context->helper->loadValue($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->addref();
            $this->context->setVariableOp($resultOp, $result);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $result);
            }

            return;
        } elseif (
            Variable::TYPE_STRING === $result->type
            && (
                Variable::TYPE_NATIVE_LONG === $value->type
                || Variable::TYPE_NATIVE_DOUBLE === $value->type
            )
        ) {
            // ?: echo phi typed as string from the else literal; true arm is strlen/crc32 (#34818).
            // Box into __value__ so merge/ECHO can hold int|string (peer bool→string #816).
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            \PHPCompiler\JIT\JitValueBox::assignToPointer(
                $this->context,
                \PHPCompiler\JIT\JitValueBox::pointer($this->context, $slot),
                $value
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->addref();
            $this->context->setVariableOp($resultOp, $result);
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName($resolved, $result);
            }

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_STRING === $value->type) {
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            $str = \PHPCompiler\JIT\JitStringArg::stringPtrFromVariable($this->context, $value);
            $owned = $this->context->builder->call(
                $this->context->lookupFunction('__string__separate'),
                $str
            );
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeString'),
                \PHPCompiler\JIT\JitValueBox::pointer($this->context, $slot),
                $owned
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $this->syncCompileTimeString($result, $value, $force);
            $result->addref();

            return;
        } elseif (Variable::TYPE_STRING === $result->type && Variable::TYPE_OBJECT === $value->type) {
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeObject'),
                \PHPCompiler\JIT\JitValueBox::pointer($this->context, $slot),
                $this->context->helper->loadValue($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->compileTimeEnumCase = $value->compileTimeEnumCase;
            $result->addref();

            return;
        } elseif (Variable::TYPE_VALUE === $result->type && Variable::TYPE_OBJECT === $value->type) {
            // Boxing Closure into a value-typed local must keep invoke metadata (#24106).
            \PHPCompiler\JIT\JitValueBox::assignToPointer(
                $this->context,
                \PHPCompiler\JIT\JitValueBox::pointer($this->context, $result->value),
                $value
            );
            $this->preserveClosureInvokeMetadata($resultOp, $result, $value);
            $result->isNullConstant = false;
            $this->syncCompileTimeString($result, $value, $force);
            $this->syncCompileTimeBcmathNumber($result, $value, $force);
            $this->syncCompileTimeDomTagName($result, $value, $force);
            $this->syncCompileTimeDatePeriod($result, $value, $force);
            $objectUserType = $value->classUserType ?? null;
            if (is_string($objectUserType) && '' !== $objectUserType) {
                $resultOp->type = new Type(Type::TYPE_OBJECT, [], $objectUserType);
            }
            $resolved = \PHPCompiler\JIT\OperandName::resolve($resultOp);
            if (null !== $resolved && '' !== $resolved) {
                $this->context->bindVariableByName(
                    $this->context->resolveRefAliasName($resolved),
                    $result
                );
            }
            $this->recordListUnpackAssignSlot($resultOp, $result);
            $result->addref();

            return;
        } elseif (Variable::TYPE_OBJECT === $result->type && Variable::TYPE_NATIVE_BOOL === $value->type) {
            // Self-host inventory spine: bool assigned into object-typed operand (#2967, #8708).
            $slot = \PHPCompiler\JIT\JitValueBox::alloc($this->context);
            \PHPCompiler\JIT\JitValueBox::writeBool(
                $this->context,
                $slot,
                $this->context->helper->loadValue($value)
            );
            $result->free();
            $result->type = Variable::TYPE_VALUE;
            $result->value = $slot;
            $result->addref();

            return;
        }
        throw new \LogicException("Cannot assign operands of different types (yet): {$value->type}, {$result->type}");
    }
}
