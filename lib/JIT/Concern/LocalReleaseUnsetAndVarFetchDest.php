<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;

/**
 * Local release / unset nulling (#36387).
 *
 * VAR_FETCH / property-fetch dest-use analysis lives in {@see VarFetchDestLvalueContext}.
 * Remaining: {@code releaseJitFunctionLocalsAtReturn} through
 * {@code bindPromotedStringConcatDest} so the hub shrinks toward split-TU
 * iterability under the size-budget ratchet.
 *
 * php-src: end-of-scope local release and unset in `Zend/zend_execute.c` /
 * `Zend/zend_vm_def.h` (ZEND_UNSET_*) — move-only Concern extract; no new C ABI
 * and no opcode/IR shape change.
 */
trait LocalReleaseUnsetAndVarFetchDest
{
    /** Release boxed locals before user function return (Zend end of scope; #4096). */
    private function releaseJitFunctionLocalsAtReturn(Block $block): void
    {
        if (null === $block->func) {
            return;
        }
        $fnName = $block->func->name;
        if ('{main}' === $fnName || str_ends_with($fnName, '::__destruct')) {
            return;
        }
        // __construct: locals assigned into `$this->prop` are propertyStore-addref'd; a
        // frame-end delref on the same named CV (often the nullable param reused as
        // `$md = new T; $this->md = $md`) drops the sole remaining ref and frees the
        // object under the property — tip reads as uninitialized / SEGV after return
        // (Slim AppFactory::create / `if (!$md) { $md = new Middleware(...); }`, #36382).
        // Peer of freeDeadVariables skip on void construct return (CompileReturn).
        // php-src: zend_execute.c ZEND_RETURN in ctors does not destroy props via CV dtors
        // when the value escaped into $this.
        if ($this->isJitConstructFrame($block)) {
            return;
        }
        $byRefParamNames = [];
        foreach ($block->paramByRef as $paramIdx => $_) {
            if (isset($block->paramNames[$paramIdx]) && '' !== $block->paramNames[$paramIdx]) {
                $byRefParamNames[$block->paramNames[$paramIdx]] = true;
            }
        }
        /** @var array<string, true> $released */
        $released = [];
        /** @var array<string, true> $localNames */
        $localNames = [];
        foreach ($this->jitFunctionNamedScopeSlots($block) as [$name, ,]) {
            if ('this' !== $name && '' !== $name) {
                $localNames[$name] = true;
            }
        }
        foreach ($this->jitFunctionAssignTargets($block) as $destOp) {
            $name = JIT\OperandName::resolve($destOp);
            if (null !== $name && '' !== $name) {
                $localNames[$name] = true;
            }
        }
        foreach ($block->orig->deadOperands ?? [] as $deadOp) {
            $name = JIT\OperandName::resolve($deadOp);
            if (null !== $name && '' !== $name) {
                $localNames[$name] = true;
            }
        }
        foreach (array_keys($localNames) as $name) {
            if (isset($released[$name])) {
                continue;
            }
            $resolved = $this->context->resolveRefAliasName($name);
            $var = $this->context->namedVariableBindings[$resolved] ?? null;
            if (null === $var) {
                continue;
            }
            $this->releaseJitCanonicalNamedLocalAtReturn(
                $name,
                $var,
                $byRefParamNames,
                $released
            );
        }
    }

    /**
     * @param array<string, true> $byRefParamNames
     * @param array<string, true> $released
     */
    private function releaseJitCanonicalNamedLocalAtReturn(
        string $name,
        Variable $var,
        array $byRefParamNames,
        array &$released
    ): void {
        if ('this' === $name || isset($released[$name])) {
            return;
        }
        if (isset($byRefParamNames[$name])) {
            return;
        }
        if (Variable::KIND_VARIABLE !== $var->kind) {
            return;
        }
        if ($var->borrowedValueEntry || null !== $var->valueBoxAliasPtr) {
            return;
        }
        if (Variable::TYPE_VALUE === $var->type) {
            $this->jitWriteNullForUnset(JIT\JitValueBox::valuePtrFromVariable($this->context, $var));
            $released[$name] = true;

            return;
        }
        // Native packed arrays (e.g. `string[1]`) still have IS_REFCOUNTED on the
        // element type. loadValue+delref would bitcast the array aggregate
        // (`[1 x %__string__*]`) to `__ref__virtual*` / i8* and fail module verify
        // (#36382 Slim/nyholm; php-src zend_array_destroy walks buckets).
        if (0 !== ($var->type & Variable::IS_NATIVE_ARRAY)) {
            $var->free();
            $released[$name] = true;

            return;
        }
        if ($var->type & Variable::IS_REFCOUNTED) {
            if (null !== $var->objectPropertySlot) {
                return;
            }
            $ptr = Variable::KIND_VALUE === $var->kind
                ? $var->value
                : $this->context->helper->loadValue($var);
            if ($this->context->type->object->hasUserDestructors()) {
                \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime::ensureLinked($this->context);
                $this->context->builder->call(
                    $this->context->lookupFunction('phpc_destruct_try_invoke'),
                    $this->context->builder->pointerCast(
                        $ptr,
                        $this->context->getTypeFromString('int8*')
                    )
                );
            }
            JIT\Builtin\WeakRefRuntime::ensureLinked($this->context);
            $this->context->builder->call(
                $this->context->lookupFunction('phpc_weakref_clear_object'),
                $this->context->builder->pointerCast(
                    $ptr,
                    $this->context->getTypeFromString('int8*')
                )
            );
            $this->context->refcount->delref($ptr);
            if (Variable::KIND_VARIABLE === $var->kind && null !== $var->value) {
                $slotTy = $var->value->typeOf();
                if (\PHPLLVM\Type::KIND_POINTER === $slotTy->getKind()) {
                    $this->context->builder->store(
                        $slotTy->getElementType()->constNull(),
                        $var->value
                    );
                }
            }
            $released[$name] = true;
        }
    }

    /**
     * @param array<string, true> $byRefParamNames
     * @param array<string, true> $released
     */
    private function releaseJitNamedLocalAtReturn(
        Block $returnBlock,
        string $name,
        int $slotIdx,
        Block $scopeBlock,
        array $byRefParamNames,
        array &$released
    ): void {
        if ('this' === $name || isset($released[$name])) {
            return;
        }
        if (isset($byRefParamNames[$name])) {
            return;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        $var = $this->context->namedVariableBindings[$resolved] ?? null;
        if (null !== $var) {
            $this->releaseJitCanonicalNamedLocalAtReturn($name, $var, $byRefParamNames, $released);

            return;
        }
        if ($slotIdx < 0) {
            return;
        }
        $scopedOp = $scopeBlock->operandForScopeSlot($slotIdx);
        if (null === $scopedOp) {
            return;
        }
        try {
            $var = $this->context->getVariableFromOp($scopedOp);
        } catch (\LogicException) {
            return;
        }
        $this->releaseJitCanonicalNamedLocalAtReturn($name, $var, $byRefParamNames, $released);
    }

    /**
     * @return list<\PHPCfg\Operand>
     */
    private function jitFunctionAssignTargets(Block $returnBlock): array
    {
        /** @var list<\PHPCfg\Operand> $targets */
        $targets = [];
        $seen = new \SplObjectStorage();
        foreach ($this->jitFunctionNamedScopeSlots($returnBlock) as [, , $scopeBlock]) {
            foreach ($this->listUnpackAssignTargetsInBlock($scopeBlock) as $dest) {
                if ($seen->contains($dest)) {
                    continue;
                }
                $seen[$dest] = true;
                $targets[] = $dest;
            }
        }

        return $targets;
    }

    /**
     * All named CV slots in the returning function — return-block scope alone omits
     * live-at-return locals php-cfg already marked dead (#36245 make_pair).
     *
     * @return \Generator<int, array{0: string, 1: int, 2: Block}, mixed, void>
     */
    private function jitFunctionNamedScopeSlots(Block $returnBlock): \Generator
    {
        $root = $this->context->jitFunctionRootBlock ?? $returnBlock;
        /** @var array<int, true> $seenBlocks */
        $seenBlocks = [];
        /** @var list<Block> $queue */
        $queue = [$root];
        while ([] !== $queue) {
            $scan = array_shift($queue);
            $blockId = spl_object_id($scan);
            if (isset($seenBlocks[$blockId])) {
                continue;
            }
            $seenBlocks[$blockId] = true;
            foreach ($scan->eachNamedScopeSlot() as [$name, $slotIdx]) {
                yield [$name, $slotIdx, $scan];
            }
            foreach ($scan->opCodes as $op) {
                foreach ([$op->block1 ?? null, $op->block2 ?? null, $op->block3 ?? null] as $target) {
                    if ($target instanceof Block && !isset($seenBlocks[spl_object_id($target)])) {
                        $queue[] = $target;
                    }
                }
            }
        }
    }

    /**
     * unset($var) on boxed locals: run __destruct before nulling when {main} defers delref destroy (#4096).
     * Also clear WeakMap/WeakReference immediately — {main} may defer __ref__delref free (#27621 / #26795).
     */
    private function jitWriteNullForUnset(\PHPLLVM\Value $valueBoxPtr): void
    {
        $map = $this->context->structFieldMap['__value__'];
        $i8 = $this->context->getTypeFromString('int8');
        $i8p = $this->context->getTypeFromString('int8*');
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($valueBoxPtr, $map['type'])
        );
        $isObject = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_OBJECT, false)
        );
        $objBlock = JIT\BasicBlockHelper::append($this->context, 'unset_object_side');
        $doneBlock = JIT\BasicBlockHelper::append($this->context, 'unset_object_done');
        $this->context->builder->branchIf($isObject, $objBlock, $doneBlock);
        $this->context->builder->positionAtEnd($objBlock);
        $obj = $this->context->builder->call(
            $this->context->lookupFunction('__value__readObject'),
            $valueBoxPtr
        );
        $objI8 = $this->context->builder->pointerCast($obj, $i8p);
        if ($this->context->type->object->hasUserDestructors()) {
            \PHPCompiler\JIT\Builtin\GcCollectCyclesRuntime::ensureLinked($this->context);
            $this->context->builder->call(
                $this->context->lookupFunction('phpc_destruct_try_invoke'),
                $objI8
            );
        }
        // WeakMap keys must drop before count() even when delref destroy is deferred (#27621).
        // Save insert point — WeakRefRuntime::ensureLinked clears the builder (#27621).
        $insertBefore = $this->context->builder->getInsertBlock();
        JIT\Builtin\WeakRefRuntime::ensureLinked($this->context);
        if (null !== $insertBefore) {
            $this->context->builder->positionAtEnd($insertBefore);
        }
        $this->context->builder->call(
            $this->context->lookupFunction('phpc_weakref_clear_object'),
            $objI8
        );
        // Zend decrements refcount on unset — valueDelref alone leaves extra GC roots (#36245).
        $this->context->refcount->delref($obj);
        $this->context->builder->branch($doneBlock);
        $this->context->builder->positionAtEnd($doneBlock);
        $this->jitNoteMemoryReleaseForUnset($valueBoxPtr);
        $this->context->builder->call(
            $this->context->lookupFunction('__value__writeNull'),
            $valueBoxPtr
        );
    }

    /**
     * Named-storage `$a = new T` keeps the object in the NEW/ASSIGN result
     * {@see __object__**} alloca as well as the CV value-box. Unset (and null
     * assign) must null those mirrors without delref — otherwise the next
     * loop-body NEW freeObjectMirrorUnlessNull double-delrefs the orphan and
     * GC sees roots=0 (#36245 loop_unset). Distinct Operand instances share a
     * CFG slot, so clear via getOperand (assign's operand) not only
     * operandForScopeSlot (prologue).
     */
    private function jitClearAssignResultObjectMirrorForNamedUnset(Block $block, ?int $unsetArgSlot): void
    {
        if (null === $unsetArgSlot) {
            return;
        }
        $targetSlot = (int) $unsetArgSlot;
        $seen = new \SplObjectStorage();
        foreach ($block->opCodes as $assignOp) {
            if (OpCode::TYPE_ASSIGN !== $assignOp->type || null === $assignOp->arg2) {
                continue;
            }
            if ((int) $assignOp->arg2 !== $targetSlot) {
                continue;
            }
            // Property/dim assigns use arg1 === arg2; still clear RHS object mirrors.
            $slots = [];
            if (null !== $assignOp->arg1 && $assignOp->arg1 !== $assignOp->arg2) {
                $slots[] = (int) $assignOp->arg1;
            }
            try {
                $rhs = $this->assignRhsSlot($assignOp);
                if ($rhs !== $targetSlot) {
                    $slots[] = $rhs;
                }
            } catch (\LogicException $e) {
                // Missing RHS slot — named unset still clears assign-result mirrors.
            }
            foreach ($slots as $slot) {
                $this->jitNullObjectMirrorForScopeSlot($block, $slot, $seen);
            }
        }
    }

    /**
     * Null every {@see __object__**} alloca bound to $slot (map + all Operand aliases).
     *
     * @param \SplObjectStorage<\PHPLLVM\Value, mixed> $seen
     */
    private function jitNullObjectMirrorForScopeSlot(Block $block, int $slot, \SplObjectStorage $seen): void
    {
        $nullObj = $this->context->getTypeFromString('__object__*')->constNull();
        if (isset($this->context->scopeSlotObjectMirrorLlvmBySlot[$slot])) {
            $llvmMirror = $this->context->scopeSlotObjectMirrorLlvmBySlot[$slot];
            if (!$seen->contains($llvmMirror)) {
                $seen[$llvmMirror] = true;
                $this->context->builder->store($nullObj, $llvmMirror);
            }
        }
        $operands = [];
        $scoped = $block->operandForScopeSlot($slot);
        if (null !== $scoped) {
            $operands[] = $scoped;
        }
        // Prefer the exact Operand getOperand returns — assign/NEW lower against it (#36245).
        $fromOpcode = $block->getOperand($slot);
        if (null !== $fromOpcode) {
            $operands[] = $fromOpcode;
        }
        foreach ($block->scopedOperands() as $scopedOp) {
            if ($block->slotForOperand($scopedOp) === $slot) {
                $operands[] = $scopedOp;
            }
        }
        foreach ($operands as $op) {
            if (!$this->context->hasVariableOp($op) && !$this->context->scope->variables->contains($op)) {
                continue;
            }
            $mirror = $this->context->hasVariableOp($op)
                ? $this->context->getVariableFromOp($op)
                : $this->context->scope->variables[$op];
            if (
                Variable::TYPE_OBJECT !== $mirror->type
                || Variable::KIND_VARIABLE !== $mirror->kind
                || null !== $mirror->objectPropertySlot
                || $mirror->functionStaticGlobal
            ) {
                continue;
            }
            if (!str_contains($this->context->getStringFromType($mirror->value->typeOf()), '__object__')) {
                continue;
            }
            if ($seen->contains($mirror->value)) {
                continue;
            }
            $seen[$mirror->value] = true;
            $this->context->builder->store($nullObj, $mirror->value);
            $this->context->scopeSlotObjectMirrorLlvmBySlot[$slot] = $mirror->value;
        }
    }

    /** Delref an {@see __object__**} mirror only when it still holds a non-null pointer (#36245). */
    private function freeObjectMirrorUnlessNull(Variable $mirror): void
    {
        $nullObj = $this->context->getTypeFromString('__object__*')->constNull();
        $loaded = $this->context->builder->load($mirror->value);
        $hasObj = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_NE,
            $loaded,
            $nullObj
        );
        $delrefBlock = JIT\BasicBlockHelper::append($this->context, 'obj_mirror_delref');
        $skipBlock = JIT\BasicBlockHelper::append($this->context, 'obj_mirror_skip');
        $this->context->builder->branchIf($hasObj, $delrefBlock, $skipBlock);
        $this->context->builder->positionAtEnd($delrefBlock);
        $this->context->refcount->delref($loaded);
        $this->context->builder->branch($skipBlock);
        $this->context->builder->positionAtEnd($skipBlock);
        // Always clear — unset may have nulled without delref; next NEW must not
        // load a stale pointer (#36245 / Variable::free peer).
        $this->context->builder->store($nullObj, $mirror->value);
    }

    /**
     * After === / !==, drop anonymous Temporary value boxes (call results). Named
     * locals stay; freeDeadVariables at block edges is too late for unset (#27118).
     */
    private function jitReleaseTempValueBoxAfterCompare(Block $block, Operand $op): void
    {
        $this->context->aliasVariableOpFromSlot($block, $op);
        if (!$this->context->hasVariableOp($op) && !$this->context->scope->variables->contains($op)) {
            $slot = $block->slotForOperand($op);
            if (null === $slot) {
                return;
            }
            $scoped = $block->operandForScopeSlot($slot);
            if (null === $scoped) {
                return;
            }
            $op = $scoped;
        }
        if (!$this->context->hasVariableOp($op) && !$this->context->scope->variables->contains($op)) {
            return;
        }
        $var = $this->context->hasVariableOp($op)
            ? $this->context->getVariableFromOp($op)
            : $this->context->scope->variables[$op];
        $name = JIT\OperandName::resolve($op);
        if (null !== $name && '' !== $name) {
            // Named locals/params must survive identical/not-identical (#31101 MiniWebApp
            // $route after !== "api/status"). Only anonymous temps are statement-end released
            // for WeakReference::get (#27118).
            return;
        }
        if (
            Variable::TYPE_VALUE !== $var->type
            || $var->functionStaticGlobal
            || $var->borrowedValueEntry
            || null !== $var->superglobalName
            || null !== $var->valueBoxAliasPtr
        ) {
            return;
        }
        if (
            Variable::KIND_VARIABLE !== $var->kind
            && Variable::KIND_VALUE !== $var->kind
        ) {
            return;
        }
        $this->jitWriteNullForUnset(
            JIT\JitValueBox::valuePtrFromVariable($this->context, $var)
        );
        if ($this->context->scope->variables->contains($op)) {
            $this->context->scope->variables->detach($op);
        }
    }

    private function jitReleasePendingWeakReferenceGetResult(): void
    {
        $op = $this->context->pendingWeakReferenceGetResult;
        $this->context->pendingWeakReferenceGetResult = null;
        if (null === $op) {
            return;
        }
        if (!$this->context->hasVariableOp($op) && !$this->context->scope->variables->contains($op)) {
            return;
        }
        $var = $this->context->hasVariableOp($op)
            ? $this->context->getVariableFromOp($op)
            : $this->context->scope->variables[$op];
        if (
            Variable::TYPE_VALUE !== $var->type
            || $var->functionStaticGlobal
            || $var->borrowedValueEntry
        ) {
            return;
        }
        $this->jitWriteNullForUnset(
            JIT\JitValueBox::valuePtrFromVariable($this->context, $var)
        );
        if ($this->context->scope->variables->contains($op)) {
            $this->context->scope->variables->detach($op);
        }
    }

    /**
     * VM releaseVmJumpIfCondTemps (#14103) for JIT: drop anonymous TYPE_VALUE boxes
     * that die in this block before branching so WeakReference::get() results do not
     * keep referents across unset in a ternary-echo merge block (#27118).
     *
     * Only considers {@see Block::$orig} deadOperands for this block — after successor
     * arms are compiled, scope also holds merge-block bindings that must not be freed here.
     */
    private function jitReleaseJumpIfAnonValueBoxes(Block $block, OpCode $jumpIf): void
    {
        $keepOps = new \SplObjectStorage();
        if (null !== $jumpIf->arg1) {
            $condOp = $this->operandAt($block, $jumpIf->arg1, 'branch condition');
            $keepOps[$condOp] = true;
        }
        foreach ($this->context->coalesceAssignTargets as $mergeOp) {
            $keepOps[$mergeOp] = true;
        }
        $toFree = [];
        $seen = new \SplObjectStorage();
        foreach ($block->orig->deadOperands as $deadOp) {
            $candidates = [$deadOp];
            $slot = $block->slotForOperand($deadOp);
            if (null !== $slot) {
                $scoped = $block->operandForScopeSlot($slot);
                if (null !== $scoped) {
                    $candidates[] = $scoped;
                }
            }
            foreach ($candidates as $op) {
                if ($seen->contains($op)) {
                    continue;
                }
                $seen[$op] = true;
                if ($keepOps->contains($op)) {
                    continue;
                }
                $name = JIT\OperandName::resolve($op);
                // Named CVs (params / locals) are never "anon" temps — nulling them at a
                // JUMPIF edge clears live values still read in ternary/if arms (#27624:
                // DNF `__value__*` param `$x` + `is_array($x) ? count($x) : …`).
                if (null !== $name && '' !== $name) {
                    continue;
                }
                if (!$this->context->scope->variables->contains($op) && !$this->context->hasVariableOp($op)) {
                    continue;
                }
                $var = $this->context->hasVariableOp($op)
                    ? $this->context->getVariableFromOp($op)
                    : $this->context->scope->variables[$op];
                // Only owned KIND_VARIABLE allocas. KIND_VALUE often aliases a live CV /
                // caller `__value__*` (DNF/mixed params); writeNull would clear storage
                // still read in ternary arms (#27624).
                if (
                    Variable::TYPE_VALUE !== $var->type
                    || Variable::KIND_VARIABLE !== $var->kind
                    || $var->functionStaticGlobal
                    || $var->borrowedValueEntry
                    || null !== $var->superglobalName
                    || null !== $var->valueBoxAliasPtr
                ) {
                    continue;
                }
                $toFree[] = $op;
            }
        }
        foreach ($toFree as $op) {
            $var = $this->context->hasVariableOp($op)
                ? $this->context->getVariableFromOp($op)
                : $this->context->scope->variables[$op];
            $this->jitWriteNullForUnset(
                JIT\JitValueBox::valuePtrFromVariable($this->context, $var)
            );
            if ($this->context->scope->variables->contains($op)) {
                $this->context->scope->variables->detach($op);
            }
        }
    }

    /** Zend emalloc parity: drop tracked bytes when unset frees a string (#7310). */
    private function jitNoteMemoryReleaseForUnset(\PHPLLVM\Value $valueBoxPtr): void
    {
        JIT\Builtin\MemoryRuntime::ensureLinked($this->context);
        $map = $this->context->structFieldMap['__value__'];
        $stringMap = $this->context->structFieldMap['__string__'];
        $i8 = $this->context->getTypeFromString('int8');
        $i64 = $this->context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $typeByte = $this->context->builder->load(
            $this->context->builder->structGep($valueBoxPtr, $map['type'])
        );
        $isString = $this->context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $doneBlock = JIT\BasicBlockHelper::append($this->context, 'unset_mem_done');
        $stringBlock = JIT\BasicBlockHelper::append($this->context, 'unset_mem_string');
        $this->context->builder->branchIf($isString, $stringBlock, $doneBlock);
        $this->context->builder->positionAtEnd($stringBlock);
        $strPtr = $this->context->builder->call(
            $this->context->lookupFunction('__value__readString'),
            $valueBoxPtr
        );
        $len = $this->context->builder->load(
            $this->context->builder->structGep($strPtr, $stringMap['length'])
        );
        $negLen = $this->context->builder->sub($zero, $len);
        JIT\Builtin\MemoryRuntime::noteAlloc($this->context, $negLen);
        $this->context->builder->branch($doneBlock);
        $this->context->builder->positionAtEnd($doneBlock);
    }

    /** Drop assign RHS / result temps so block-end dead-operand free cannot re-delref (#4096). */
    private function jitClearAssignTempOperand(Operand $op): void
    {
        $this->jitWriteNullOperand($op);
        if ($this->context->scope->variables->contains($op)) {
            $this->context->scope->variables->detach($op);
        }
    }

    /** Mirror VM assign: clear dead assign-result / RHS temps (#4096). */
    private function jitWriteNullOperand(Operand $op): void
    {
        if (!$this->context->hasVariableOp($op)) {
            return;
        }
        $var = $this->context->getVariableFromOp($op);
        if (Variable::KIND_VARIABLE === $var->kind && Variable::TYPE_VALUE === $var->type) {
            $this->context->builder->call(
                $this->context->lookupFunction('__value__writeNull'),
                $var->value
            );

            return;
        }
        if (
            Variable::TYPE_OBJECT === $var->type
            && Variable::KIND_VARIABLE === $var->kind
            && null !== $var->value
            && \in_array($var->value, $this->context->scopeSlotObjectMirrorLlvmBySlot, true)
        ) {
            $isCanonicalCv = false;
            foreach ($this->context->namedVariableBindings as $bound) {
                if ($bound === $var) {
                    $isCanonicalCv = true;
                    break;
                }
            }
            if (!$isCanonicalCv) {
                $slotTy = $var->value->typeOf();
                if (\PHPLLVM\Type::KIND_POINTER === $slotTy->getKind()) {
                    $this->context->builder->store(
                        $slotTy->getElementType()->constNull(),
                        $var->value
                    );
                }

                return;
            }
        }
        $var->free();
    }

    /**
     * php-cfg ASSIGN(resultTemp, namedAlias, rhs) — mark the named CV, not only the dead temp (#36405).
     */
    private function propagateAssignAliasBinding(
        ?Operand $destOp,
        Operand $aliasOp,
        bool $namedAliasReceivedAssign
    ): void {
        $aliasName = JIT\OperandName::resolve($aliasOp);
        if (null === $aliasName || '' === $aliasName) {
            return;
        }
        if ($namedAliasReceivedAssign) {
            if ($this->context->hasVariableOp($aliasOp)) {
                JIT\UndefinedVariableHelper::markAssigned(
                    $this->context,
                    $aliasOp,
                    $this->context->getVariableFromOp($aliasOp)
                );
            }

            return;
        }
        if (null !== $destOp && $this->context->hasVariableOp($destOp)) {
            $destVar = $this->context->getVariableFromOp($destOp);
            $this->context->setVariableOp($aliasOp, $destVar);
            $this->context->bindVariableByName(
                $this->context->resolveRefAliasName($aliasName),
                $destVar
            );
            JIT\UndefinedVariableHelper::markAssigned($this->context, $aliasOp, $destVar);
        }
    }

    private function maybeBindNamedVariable(Operand $op): void
    {
        if (!$this->context->hasVariableOp($op)) {
            return;
        }
        $name = JIT\OperandName::resolve($op);
        if (null === $name || '' === $name) {
            return;
        }
        // Prefer the Operand's scope Variable over getVariableFromOp(): the latter
        // prefers namedVariableBindings and can resurrect a stale formal after
        // assignOperand setVariableOp'd a fresh VALUE/hashtable slot (FastRoute
        // `$options = ['k' => …]` then `$options['k']` → Undefined array key, #36382).
        // php-src: Zend/zend_execute.c ZEND_ASSIGN into CV; zend_hash_find on that CV.
        $var = $this->context->scope->variables[$op];
        $this->context->bindVariableByName($name, $var);
        // TYPE_ASSIGN dest is a defined CV for later ZEND_CHECK_UNDEFINED_VAR (#32041).
        JIT\UndefinedVariableHelper::markAssigned($this->context, $op, $var);
    }

    /**
     * After KIND_VALUE→alloca CONCAT promotion, rebind every Operand for the dest
     * scope slot (named local + unnamed Temporary) so in-place `$out .=` loads and
     * stores the same alloca across loop iterations (#22845).
     */
    private function bindPromotedStringConcatDest(Block $block, Operand $destOp, Variable $promoted): void
    {
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
                $this->context->setVariableOp($scopeOp, $promoted);
                $scopeName = JIT\OperandName::resolve($scopeOp);
                if (null !== $scopeName && '' !== $scopeName) {
                    $names[$scopeName] = true;
                }
            }
        }
        foreach ($names as $name => $_) {
            $this->context->bindVariableByName((string) $name, $promoted);
        }
        $this->markScopeVariableAssignedIfTracked($destOp, $promoted);
        if (null !== $slot) {
            foreach ($block->scopedOperands() as $scopeOp) {
                if ($block->slotForOperand($scopeOp) !== $slot) {
                    continue;
                }
                $this->markScopeVariableAssignedIfTracked($scopeOp, $promoted);
            }
        }
    }
}
