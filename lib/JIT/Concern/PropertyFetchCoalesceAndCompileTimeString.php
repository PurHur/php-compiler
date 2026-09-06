<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPLLVM;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;

/**
 * Property-fetch / coalesce-merge binding for JIT/AOT (#36387).
 *
 * Compile-time string fold/promote helpers live in
 * {@see CompileTimeStringFoldAndPromote}. Remaining methods:
 * {@code ternaryEchoPhiPropertyFetchDest} through {@code loadPropertyFetchReceiver}.
 *
 * php-src: object property read/write and coalesce merge in
 * `Zend/zend_object_handlers.c` / `Zend/zend_execute.c` — move-only Concern extract;
 * no new C ABI and no opcode/IR shape change.
 */
trait PropertyFetchCoalesceAndCompileTimeString
{
    private function ternaryEchoPhiPropertyFetchDest(Block $block, int $fetchIndex): ?Operand
    {
        $fetch = $block->opCodes[$fetchIndex];
        if (OpCode::TYPE_PROPERTY_FETCH !== $fetch->type && OpCode::TYPE_PROPERTY_FETCH_WRITE !== $fetch->type) {
            return null;
        }
        $fetchResultSlot = (int) $fetch->arg1;
        $next = $block->opCodes[$fetchIndex + 1] ?? null;
        if (null === $next || OpCode::TYPE_ASSIGN !== $next->type) {
            return null;
        }
        if ((int) $next->arg2 !== $fetchResultSlot || (int) $next->arg3 !== $fetchResultSlot) {
            return null;
        }
        $dest = $block->getOperand($next->arg1);
        if (!$this->context->coalesceAssignTargets->contains($dest)) {
            return null;
        }

        return $dest;
    }

    /**
     * After ??= arms persist the store, drop fetch-arm property SSA so the merge
     * block (and nested outer ??) load the stack box (#33760 / #32988).
     *
     * ASSIGN_REF aliases (`$r =& $obj->prop; $r ??= …`) already have a dominating
     * GEP from the ref bind — stripping here makes the right-arm store a local-box
     * no-op (leftover of #35898 / #33748).
     */
    private function reseatCoalesceResultAfterPropertyArms(Operand $coalesceResult): void
    {
        $this->ensureCoalesceMergeStackSlot($coalesceResult);
        if (!$this->context->hasVariableOp($coalesceResult)) {
            return;
        }
        $mergeSeat = $this->context->getVariableFromOp($coalesceResult);
        $namedRefAlias = $mergeSeat->assignRefLvalueAlias
            && null !== JIT\OperandName::resolve($coalesceResult)
            && '' !== (string) JIT\OperandName::resolve($coalesceResult);
        if ($namedRefAlias) {
            return;
        }
        $mergeSeat->objectPropertySlot = null;
        $mergeSeat->objectPropertyType = null;
        $mergeSeat->objectPropertyReceiver = null;
        $mergeSeat->objectPropertyName = null;
        $mergeSeat->objectPropertyClassName = null;
        $mergeSeat->objectPropertyDnfArms = null;
        $mergeSeat->staticPropertyGlobal = null;
        $mergeSeat->staticPropertyType = null;
    }

    private function stampPropertyFetchReceiverOp(Variable $fetched, Operand $receiverOp): void
    {
        $fetched->objectPropertyReceiverOp = $receiverOp;
        if (
            'DOMNodeList' !== ($fetched->classUserType ?? '')
            || null !== ($fetched->compileTimeDomNodeListLength ?? null)
            || !$this->context->hasVariableOp($receiverOp)
        ) {
            return;
        }
        $receiverVar = $this->context->getVariableFromOp($receiverOp);
        $local = $receiverVar->compileTimeDomAttrLocalName ?? null;
        if (null === $local) {
            return;
        }
        $valueLit = $this->context->extensionLowering->domCompileTime?->compileTimeAttrValuePublic(
            $receiverVar->compileTimeDomAttrNamespace ?? '',
            $local
        );
        if (null !== $valueLit) {
            $fetched->compileTimeDomNodeListLength = '' !== $valueLit ? 1 : 0;
        }
    }

    private function releaseCoalesceMergeSlotMapping(Block $block, Operand $coalesceResult): void
    {
        $mergeSlot = $block->slotForOperand($coalesceResult);
        if (null !== $mergeSlot) {
            unset($this->context->coalesceMergeSlotOperands[$mergeSlot]);
        }
    }

    /**
     * Copy a native declared property read into a stack slot — loop JUMPIF must not
     * compare through a live objectPropertySlot or boxed __value__ alias (#36018).
     */
    private function snapshotNativeScalarPropertyRead(Variable $fetched, int $propType): Variable
    {
        $loaded = $this->context->helper->loadValue($fetched);
        $tyName = match ($propType) {
            Variable::TYPE_NATIVE_BOOL => 'int1',
            Variable::TYPE_NATIVE_DOUBLE => 'double',
            default => 'int64',
        };
        $ty = $this->context->getTypeFromString($tyName);
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $ty);
        $this->context->builder->store($loaded, $slot);
        $snap = new Variable(
            $this->context,
            $propType,
            Variable::KIND_VARIABLE,
            $slot
        );
        $snap->addref();
        $snap->compileTimeLong = null;
        $snap->compileTimeFloat = null;
        if (null !== $fetched->compileTimeDomNodeListLength) {
            $snap->compileTimeDomNodeListLength = $fetched->compileTimeDomNodeListLength;
        }

        return $snap;
    }

    /**
     * `$len = $n->length` in user functions must bind native i64 like `(int)$n->length` (#36018).
     */
    private function coerceNamedLocalNativeLongPropertyAssign(Operand $resultOp, Variable $value): Variable
    {
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name) {
            return $value;
        }
        $block = $this->context->jitEnclosingBlock;
        if (null === $block || null === $block->func || $block->isMainScript()) {
            return $value;
        }
        if (Variable::TYPE_VALUE !== $value->type || !JIT\JitValueBox::isValueOperand($value)) {
            return $value;
        }
        $isNativeLongProp = Variable::TYPE_NATIVE_LONG === ($value->objectPropertyType ?? null);
        $isDomNodeListLen = null !== ($value->compileTimeDomNodeListLength ?? null);
        if (!$isNativeLongProp && !$isDomNodeListLen) {
            return $value;
        }
        $long = ext\standard\JitZendScalarCast::emitIntCast($this->context, $value);
        $i64 = $this->context->getTypeFromString('int64');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $i64);
        $this->context->builder->store($long, $slot);
        $native = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VARIABLE,
            $slot
        );
        $native->addref();
        $native->compileTimeLong = null;
        if ($isDomNodeListLen) {
            $native->compileTimeDomNodeListLength = $value->compileTimeDomNodeListLength;
        }

        return $native;
    }

    /**
     * Read fetches keep objectPropertySlot on branch-local SSA; ARG_SEND / var_dump load it later
     * from a block where the GEP does not dominate (#33760, peer #32988).
     */
    /**
     * Bind a property fetch result. Write / dim-write keep the live slot (no value-box reseat)
     * so `$r =& $o->p[$k]; $s =& $o->p[$k]` share one HT (#35980).
     *
     * @see php-src Zend/zend_object_handlers.c zend_std_get_property_ptr_ptr
     */
    private function bindPropertyFetchResult(Operand $result, Variable $fetched, bool $forWrite): void
    {
        if ($forWrite) {
            $this->context->scope->variables[$result] = $fetched;
            $this->context->setVariableOp($result, $fetched);

            return;
        }
        $boxed = $this->reseatPropertyFetchReadIntoValueBox($fetched);
        $this->context->scope->variables[$result] = $boxed;
        $this->applyTypedPropertyFetchResultType($result, $boxed);
    }

    private function reseatPropertyFetchReadIntoValueBox(Variable $fetched): Variable
    {
        if (null === $fetched->objectPropertySlot) {
            return $fetched;
        }
        $propType = $fetched->objectPropertyType ?? $fetched->type;
        // Native scalar declared properties (e.g. DOMNodeList::$length) must not stay
        // live-slot aliased or __value__-boxed — loop `$i < $len` needs snapshot i64 (#36018).
        if (\in_array($propType, [
            Variable::TYPE_NATIVE_LONG,
            Variable::TYPE_NATIVE_BOOL,
            Variable::TYPE_NATIVE_DOUBLE,
        ], true)) {
            return $this->snapshotNativeScalarPropertyRead($fetched, $propType);
        }
        $slot = JIT\JitValueBox::alloc($this->context);
        JIT\Builtin\Type\ObjectInstancePropertyLlvm::boxFetchedPropertyIntoValue(
            $this->context->type->object,
            $slot,
            $fetched,
            $propType
        );
        $boxed = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $boxed->compileTimeString = $fetched->compileTimeString;
        // Runtime boxFetchedPropertyIntoValue is authoritative — fetch-temp compileTimeLong
        // can be stale/wrong and made `$obj->n ** 2` / `$t = $obj->n; $t ** 2` fold 1 (#35978).
        $boxed->isNullConstant = $fetched->isNullConstant;
        // Keep typed-prop identity for BP_VAR_R guards (echo/loadValue). Stripping these
        // made unset string props echo garbage instead of Error (#33886 / re-#33007);
        // isset/?? use loadValueQuietForIsset and stay silent (#29688).
        $this->copyObjectPropertyBacking($boxed, $fetched);
        // documentElement/firstChild temps lose loadXML stamps when boxed — C14N fold and
        // appendChild refreshCompileTimeXmlWithRootInner need compileTimeDomLoadXml (#32978).
        $this->syncCompileTimeDomTagName($boxed, $fetched, true);
        if (null !== $fetched->classUserType) {
            $boxed->classUserType = $fetched->classUserType;
        }
        if (null !== $fetched->compileTimeDomNodeListLength) {
            $boxed->compileTimeDomNodeListLength = $fetched->compileTimeDomNodeListLength;
        }
        if (null !== $fetched->compileTimeDomAttrLocalName) {
            $boxed->compileTimeDomAttrLocalName = $fetched->compileTimeDomAttrLocalName;
            $boxed->compileTimeDomAttrNamespace = $fetched->compileTimeDomAttrNamespace ?? '';
        }
        $constraintUserType = $this->typedPropertyClassConstraintUserType($fetched);
        if (null !== $constraintUserType) {
            $boxed->classUserType = $constraintUserType;
        }
        if (null !== $fetched->compileTimeDateTimeTimestamp) {
            $boxed->compileTimeDateTimeTimestamp = $fetched->compileTimeDateTimeTimestamp;
            $boxed->compileTimeDateTimeMicrosecond = $fetched->compileTimeDateTimeMicrosecond;
            $boxed->compileTimeTimezoneName = $fetched->compileTimeTimezoneName;
            $boxed->compileTimeDateTimeClassName = $fetched->compileTimeDateTimeClassName;
        } elseif (null !== $constraintUserType) {
            $dateLc = strtolower(ltrim($constraintUserType, '\\'));
            if (\in_array($dateLc, ['datetime', 'datetimeimmutable'], true)) {
                $boxed->compileTimeDateTimeClassName = 'datetimeimmutable' === $dateLc
                    ? 'DateTimeImmutable'
                    : 'DateTime';
            }
        }

        return $boxed;
    }

    /**
     * Typed property fetch results carry objectPropertyClassConstraint, not CFG userType.
     * Without this, `$sub->dt->format()` in global scope resolves Sub::format (#35752).
     */
    private function typedPropertyClassConstraintUserType(JIT\Variable $var): ?string
    {
        $constraint = $var->objectPropertyClassConstraint ?? null;
        if (!\is_string($constraint) || '' === $constraint) {
            return null;
        }
        $lc = strtolower(ltrim($constraint, '\\'));
        if (\in_array($lc, [
            'int', 'float', 'string', 'bool', 'array', 'object', 'mixed', 'null',
            'callable', 'iterable', 'resource', 'void', 'never', 'false', 'true', 'null',
        ], true)) {
            return null;
        }

        return ltrim($constraint, '\\');
    }

    private function applyTypedPropertyFetchResultType(Operand $result, JIT\Variable $var): void
    {
        $userType = $this->typedPropertyClassConstraintUserType($var);
        if (null === $userType) {
            return;
        }
        $var->classUserType = $userType;
        $result->type = Type::object($userType);
    }

    /**
     * ?? / ??= fetch arms bind objectPropertySlot in branch-only SSA. Later PROPERTY_FETCH or
     * ARG_SEND that reuses the scope Variable loads a GEP that does not dominate — e.g. second
     * `$a->p ??= $b->q ??=` then var_dump($a->p, $b->q) (#33760, peer #32988).
     */
    private function clearCoalesceFetchArmPropertySlotsInScope(): void
    {
        foreach ($this->context->scope->variables as $op) {
            if ($this->context->coalesceAssignTargets->contains($op)) {
                continue;
            }
            $var = $this->context->scope->variables[$op];
            if (null === $var->objectPropertySlot) {
                continue;
            }
            $var->objectPropertySlot = null;
            $var->objectPropertyType = null;
            $var->objectPropertyReceiver = null;
            $var->objectPropertyName = null;
            $var->objectPropertyClassName = null;
            $var->objectPropertyDnfArms = null;
        }
    }

    /**
     * ??= merge temps are stack slots. Class::$prop fetch binds KIND_VALUE plus
     * staticPropertyGlobal; promoting first drops that lvalue so the store never
     * reaches the module global and AOT readback stays NULL (#32035, #20877).
     * Instance `$o->p ??=` is the same shape with objectPropertySlot (#33748 / re-#32880).
     */
    private function persistPropertyBeforeCoalesceMergePromote(Operand $coalesceTarget, Variable $value): void
    {
        if (!$this->context->hasVariableOp($coalesceTarget)) {
            return;
        }
        $dest = $this->context->getVariableFromOp($coalesceTarget);
        if (
            (null === $dest->staticPropertyGlobal || null === $dest->staticPropertyType)
            && (null === $dest->objectPropertySlot || null === $dest->objectPropertyType)
        ) {
            return;
        }
        $this->assignOperand($coalesceTarget, $value, false);
    }

    /**
     * ?: / ?? merge ASSIGN dest — match coalesceAssignTargets or the stack-phi slot map.
     *
     * php-cfg reuses slot numbers across JUMPIF arms but not always the same Operand
     * instance; the else arm alias temp must resolve via coalesceMergeSlotOperands (#34956).
     */
    private function bindCoalesceMergeSlotVariable(Block $block, int $slot, Variable $var): void
    {
        if (isset($this->context->coalesceMergeSlotOperands[$slot])) {
            $this->context->setVariableOp($this->context->coalesceMergeSlotOperands[$slot], $var);
        }
        $aliasOp = $block->getOperand($slot);
        if ($aliasOp instanceof Operand) {
            $this->context->setVariableOp($aliasOp, $var);
        }
        foreach ($this->context->scope->variables as $scopeOp) {
            if (!$scopeOp instanceof Operand) {
                continue;
            }
            if ($block->slotForOperand($scopeOp) !== $slot) {
                continue;
            }
            $this->context->setVariableOp($scopeOp, $var);
        }
    }

    private function resolveCoalesceMergeAssignTarget(
        ?Operand $destOp,
        ?Operand $aliasOp,
        Block $block
    ): ?Operand {
        if (null !== $destOp && $this->context->coalesceAssignTargets->contains($destOp)) {
            return $destOp;
        }
        if (null !== $aliasOp && $this->context->coalesceAssignTargets->contains($aliasOp)) {
            return $aliasOp;
        }
        foreach ([$aliasOp, $destOp] as $op) {
            if (null === $op) {
                continue;
            }
            $slot = $block->slotForOperand($op);
            if (null !== $slot && isset($this->context->coalesceMergeSlotOperands[$slot])) {
                return $this->context->coalesceMergeSlotOperands[$slot];
            }
        }

        return null;
    }

    private function ensureCoalesceMergeStackSlot(Operand $mergeOp): void
    {
        if ($this->context->hasVariableOp($mergeOp)) {
            $var = $this->context->getVariableFromOp($mergeOp);
            if (
                Variable::TYPE_VALUE === $var->type
                && Variable::KIND_VARIABLE === $var->kind
            ) {
                // Property-backed slots carry a fetch-arm-only SSA pointer (objectPropertySlot).
                // Keep the alloca (already written by the fetch/null arm) but drop the backing so
                // nullsafe_merge reads the stack box — otherwise Module verify fails (#32988).
                // `$r =& $obj->prop` GEP dominates both ??= arms; dropping it here leaves
                // `$r ??= n` as a local-box write (#35987 leftover of #35898).
                // Only named CVs: FETCH_OBJ_W temps for `$a->p ??= $b->q ??=` must still
                // drop arm-local GEPs (#33760 / #32988).
                $namedRefAlias = $var->assignRefLvalueAlias
                    && null !== JIT\OperandName::resolve($mergeOp)
                    && '' !== (string) JIT\OperandName::resolve($mergeOp);
                if (
                    !$namedRefAlias
                    && (
                        null !== $var->objectPropertySlot
                        || null !== $var->staticPropertyGlobal
                        || null !== $var->valueBoxAliasPtr
                        || $var->functionStaticGlobal
                    )
                ) {
                    $var->objectPropertySlot = null;
                    $var->objectPropertyType = null;
                    $var->objectPropertyReceiver = null;
                    $var->objectPropertyName = null;
                    $var->objectPropertyClassName = null;
                    $var->objectPropertyDnfArms = null;
                    $var->staticPropertyGlobal = null;
                    $var->staticPropertyType = null;
                    $var->valueBoxAliasPtr = null;
                    $var->functionStaticGlobal = false;
                }

                return;
            }
        }
        $slot = JIT\JitValueBox::alloc($this->context);
        $this->context->setVariableOp(
            $mergeOp,
            new Variable(
                $this->context,
                Variable::TYPE_VALUE,
                Variable::KIND_VARIABLE,
                $slot
            )
        );
    }

    /**
     * Merge-block ECHO may reference the ternary alias temp; redirect to the phi dest (#18052).
     */
    private function recordTernaryEchoPhiByAliasSlot(
        Block $block,
        OpCode $op,
        Operand $destOp,
        ?Operand $aliasOp,
        int $rhsSlot
    ): void {
        if (null === $aliasOp || $op->arg1 === $op->arg2) {
            return;
        }
        $aliasSlot = $block->slotForOperand($aliasOp);
        if (null === $aliasSlot) {
            return;
        }
        $phiOp = $this->context->coalesceAssignTargets->contains($destOp) || $op->arg2 === $rhsSlot
            ? $destOp
            : $block->getOperand($rhsSlot);
        $this->context->ternaryEchoPhiByAliasSlot[$aliasSlot] = $phiOp;
    }

    private function loadPropertyFetchReceiver(Operand $objOp): PHPLLVM\Value
    {
        $name = JIT\OperandName::resolve($objOp);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                if (Variable::TYPE_OBJECT === $bound->type) {
                    return $this->context->helper->loadValue($bound);
                }
            }
        }
        $var = $this->context->getVariableFromOpInScopes($objOp);
        if (Variable::TYPE_OBJECT === $var->type) {
            return $this->context->helper->loadValue($var);
        }
        if (Variable::TYPE_VALUE === $var->type) {
            return $this->context->builder->call(
                $this->context->lookupFunction('__value__readObject'),
                JIT\JitValueBox::valuePtrFromVariable($this->context, $var)
            );
        }

        throw new \LogicException(
            'Property fetch receiver must be object or object-valued property, got '
            .Variable::getStringType($var->type)
        );
    }

    private static function foreachContainerUserType(
        Operand $arrayOp,
        ?JIT\Variable $arrayVar = null
    ): ?string {
        $userType = $arrayOp->type->userType ?? null;
        if (null !== $userType && '' !== $userType) {
            return $userType;
        }
        if (null !== $arrayOp->type && Variable::TYPE_HASHTABLE === Variable::getTypeFromType($arrayOp->type)) {
            $decl = $arrayOp->type->userType ?? null;
            if (null !== $decl && 0 === strcasecmp($decl, 'SplObjectStorage')) {
                return 'SplObjectStorage';
            }
        }
        // Property fetches (childNodes) tag DOMNodeList on the JIT binding, not CFG userType (#33082).
        $tagged = $arrayVar->classUserType ?? null;
        if (null !== $tagged && '' !== $tagged) {
            return $tagged;
        }

        return null;
    }

}
