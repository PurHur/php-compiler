<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;

/**
 * Script-global assign / native-long widen and assign-lvalue resolve (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code promoteNativeLongLvalueToValueBox}
 * through {@code resolveScriptGlobalAssignTarget} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_execute_API.c zend_set_local_var / EG(symbol_table) script
 * globals; Zend/zend_execute.c assign paths — move-only Concern extract; no new
 * C ABI and no opcode/IR shape change.
 */
trait ScriptGlobalAssignAndLvalueResolve
{
    private function promoteNativeLongLvalueToValueBox(
        Operand $resultOp,
        Variable $result,
        Variable $value
    ): void {
        // When the old lvalue was an i64 alloca, already-compiled loop headers
        // still read from it. Write the long component of the value box back to
        // the old alloca so backedges see the updated value (#32605).
        $oldAlloca = null;
        if (Variable::KIND_VARIABLE === $result->kind
            && Variable::TYPE_NATIVE_LONG === $result->type
            && null !== $result->value
        ) {
            $oldAlloca = $result->value;
        }
        if (!$result->includeBinding) {
            $result->free();
        }
        $slot = JIT\JitValueBox::alloc($this->context);
        $slotPtr = JIT\JitValueBox::pointer($this->context, $slot);
        JIT\JitValueBox::assignToPointer(
            $this->context,
            $slotPtr,
            $value
        );
        if (null !== $oldAlloca) {
            $readLong = $this->context->lookupFunction('__value__readLong');
            $longVal = $this->context->builder->call($readLong, $slotPtr);
            $this->context->builder->store($longVal, $oldAlloca);
        }
        $promoted = new Variable(
            $this->context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $promoted->compileTimeConstantName = $value->compileTimeConstantName;
        $promoted->compileTimeEnumCase = $value->compileTimeEnumCase;
        $promoted->compileTimeFloat = $value->compileTimeFloat;
        $promoted->compileTimeLong = $value->compileTimeLong;
        $this->syncCompileTimeString($promoted, $value, false);
        $this->context->setVariableOp($resultOp, $promoted);
        $resolved = JIT\OperandName::resolve($resultOp);
        if (null !== $resolved && '' !== $resolved) {
            $this->context->bindVariableByName(
                $this->context->resolveRefAliasName($resolved),
                $promoted
            );
        }
        // Int-local widen to VALUE must mark assigned — guards only track VALUE slots
        // (#23471 e28/mandelbrot spurious undef warnings after #35643).
        $this->markScopeVariableAssignedIfTracked($resultOp, $promoted);
    }

    /**
     * Widen `$x = 0; $x = 0.7` locals to a native double alloca instead of a heap
     * __value__ box so float loops stay on fadd/fmul (#36407 / #23471).
     */
    private function promoteNativeLongLvalueToNativeDouble(
        Operand $resultOp,
        Variable $result,
        Variable $value
    ): void {
        $oldAlloca = null;
        if (Variable::KIND_VARIABLE === $result->kind
            && Variable::TYPE_NATIVE_LONG === $result->type
            && null !== $result->value
        ) {
            $oldAlloca = $result->value;
        }
        if (!$result->includeBinding) {
            $result->free();
        }
        $doubleTy = $this->context->getTypeFromString('double');
        $slot = JIT\BasicBlockHelper::entryAlloca($this->context, $doubleTy);
        if (Variable::TYPE_NATIVE_DOUBLE === $value->type) {
            $fp = $this->context->helper->loadValue($value);
        } elseif (Variable::TYPE_VALUE === $value->type && null !== $value->compileTimeFloat) {
            $fp = $doubleTy->constReal($value->compileTimeFloat);
        } else {
            throw new \LogicException('promoteNativeLongLvalueToNativeDouble: unexpected value type '.$value->type);
        }
        $this->context->builder->store($fp, $slot);
        if (null !== $oldAlloca) {
            $longVal = $this->context->builder->fpToSi(
                $fp,
                $this->context->getTypeFromString('int64')
            );
            $this->context->builder->store($longVal, $oldAlloca);
        }
        $promoted = new Variable(
            $this->context,
            Variable::TYPE_NATIVE_DOUBLE,
            Variable::KIND_VARIABLE,
            $slot
        );
        $promoted->compileTimeConstantName = $value->compileTimeConstantName;
        $promoted->compileTimeEnumCase = $value->compileTimeEnumCase;
        $promoted->compileTimeFloat = $value->compileTimeFloat;
        $promoted->compileTimeLong = null;
        $this->syncCompileTimeString($promoted, $value, false);
        $this->context->setVariableOp($resultOp, $promoted);
        $resolved = JIT\OperandName::resolve($resultOp);
        if (null !== $resolved && '' !== $resolved) {
            $this->context->bindVariableByName(
                $this->context->resolveRefAliasName($resolved),
                $promoted
            );
        }
        $this->markScopeVariableAssignedIfTracked($resultOp, $promoted);
    }

    private function nativeLongWidenAssignIsNativeDouble(Variable $value): bool
    {
        return Variable::TYPE_NATIVE_DOUBLE === $value->type
            || (Variable::TYPE_VALUE === $value->type && null !== $value->compileTimeFloat);
    }

    /**
     * First assignment to a script global must populate the heap box (#1492 bootstrap-aot).
     *
     * Without this, makeVariableFromValueOp keeps an SSA rvalue while a later VAR_FETCH rebinds
     * the name to an empty script-global wrapper — SplObjectStorage::contains() then reads null.
     *
     * Also covers `global $name` imports after foreach/try phi merges drop the slot binding (#16828).
     */
    private function tryAssignScriptGlobalFirstBinding(Operand $resultOp, JIT\Variable $value): bool
    {
        $block = $this->context->jitEnclosingBlock;
        if (null === $block) {
            return false;
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name) {
            $slot = $block->slotForOperand($resultOp);
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
        if (null === $name || '' === $name || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
            return false;
        }
        $mainScriptGlobal = $block->isMainScript() && !$this->context->isForeachByRefLocalName($name, $block);
        $importedGlobal = isset($this->context->jitImportedGlobalNames[$name]);
        if (!$mainScriptGlobal && !$importedGlobal) {
            return false;
        }
        // Native scalar {main} counters use stack allocas — do not re-bind them onto a
        // heap __value__ box (that undoes Variable::fromOp / #36408).
        $resolved = $this->context->resolveRefAliasName($name);
        if (isset($this->context->namedVariableBindings[$resolved])) {
            $existing = $this->context->namedVariableBindings[$resolved];
            if (
                Variable::TYPE_NATIVE_LONG === $existing->type
                || Variable::TYPE_NATIVE_BOOL === $existing->type
                || Variable::TYPE_NATIVE_DOUBLE === $existing->type
            ) {
                return false;
            }
        }
        $globalVar = $this->context->ensureScriptGlobal($name);
        $this->context->setVariableOp($resultOp, $globalVar);
        $globalPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $globalVar);
        JIT\JitValueBox::assignToPointer(
            $this->context,
            $globalPtr,
            $value
        );
        JIT\JitValueBox::publishAfterWrite($this->context, $globalPtr);
        $this->invalidateScriptGlobalCompileTimeMetadata($globalVar);
        $globalVar->compileTimeEnumCase = $value->compileTimeEnumCase;
        $this->syncCompileTimeString($globalVar, $value, false);
        $this->syncCompileTimeBcmathNumber($globalVar, $value, false);
        $this->syncCompileTimeDomTagName($globalVar, $value, false);
        $this->syncCompileTimeDatePeriod($globalVar, $value, false);
        $this->context->bindVariableByName($this->context->resolveRefAliasName($name), $globalVar);
        $this->markScopeVariableAssignedIfTracked($resultOp, $globalVar);

        return true;
    }

    /**
     * Publish CONCAT into the {main} script-global box ECHO reads (#36366).
     *
     * Uses the opcode {@see Block} (same as attachEchoScriptGlobalName), not
     * {@see Context::$jitEnclosingBlock}, which can disagree mid-compile.
     */
    private function publishConcatResultToMainScriptGlobal(
        Block $block,
        Operand $destOp,
        JIT\Variable $value,
        ?int $destSlot = null
    ): bool {
        if (!$block->isMainScript()) {
            return false;
        }
        // Same name resolution markAssigned uses — OperandName alone can miss when
        // the CONCAT dest Temporary shares a slot with a named Variable (#36366).
        $name = null;
        if (null !== $destSlot) {
            $name = $this->resolveLocalNameForOperand($block, $destOp, $destSlot);
        }
        if (null === $name || '' === $name) {
            $name = JIT\UndefinedVariableHelper::resolveTrackableName($destOp, $value);
        }
        if (null === $name || '' === $name) {
            $name = JIT\OperandName::resolve($destOp);
        }
        if (null === $name || '' === $name) {
            $slot = $destSlot ?? $block->slotForOperand($destOp);
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
        if (
            null === $name
            || '' === $name
            || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)
            || $this->context->isForeachByRefLocalName($name, $block)
        ) {
            return false;
        }
        $globalVar = $this->context->ensureScriptGlobal($name);
        $globalPtr = JIT\JitValueBox::valuePtrFromVariable($this->context, $globalVar);
        JIT\JitValueBox::assignToPointer($this->context, $globalPtr, $value);
        JIT\JitValueBox::publishAfterWrite($this->context, $globalPtr);
        $this->invalidateScriptGlobalCompileTimeMetadata($globalVar);
        $this->syncCompileTimeString($globalVar, $value, false);
        // Keep dest / named binding on the native `__string__*` result. Rebinding to
        // the script-global TYPE_VALUE box made ECHO read an empty heap box when
        // assignToPointer did not materialize the string bytes (#36366).
        $this->context->setVariableOp($destOp, $value);
        $this->context->bindVariableByName($this->context->resolveRefAliasName($name), $value);
        $this->markScopeVariableAssignedIfTracked($destOp, $value);

        return true;
    }

    /**
     * Script-global heap boxes keep stale {@see JIT\Variable::$compileTimeLong} after ++/-- or
     * assign-op unless cleared; echo must not constant-fold those operands (#23842).
     */
    private function invalidateScriptGlobalCompileTimeMetadata(JIT\Variable $global): void
    {
        if (!$global->functionStaticGlobal) {
            return;
        }
        $global->compileTimeLong = null;
        $global->compileTimeFloat = null;
        $global->compileTimeString = null;
        $global->compileTimeBcmathNumber = null;
        $global->isNullConstant = false;
        $global->compileTimeConstantName = null;
        $global->compileTimeEnumCase = null;
    }

    /**
     * Re-bind echo/print operands to the module-global heap box when the name is a script global.
     *
     * Scope slots can retain TYPE_NATIVE_LONG rvalues from an earlier literal assign even after
     * inc/dec or assign-op updated the heap box (#23842).
     */
    private function resolveScriptGlobalForRuntimeRead(
        Operand $op,
        ?Block $block = null,
        ?string $nameOverride = null,
        bool $skipUndefGuard = false
    ): ?JIT\Variable {
        $name = $nameOverride ?? JIT\OperandName::resolve($op);
        if (null === $name || '' === $name || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
            return null;
        }
        $block ??= $this->context->jitFunctionRootBlock ?? $this->context->jitEnclosingBlock;
        if (null === $block) {
            return null;
        }
        if ($block->isMainScript() && !$this->context->isForeachByRefLocalName($name, $block)) {
            if ($this->shouldDeferScriptGlobalForInlineIncludeBinding($name, $op, $block)) {
                return null;
            }
            // Native scalar {main} counters live in stack allocas — do not redirect reads
            // onto an empty heap box (#36408). Boxed float/int results from property
            // arithmetic also land in a local `__value__` alloca while the module
            // script-global stays null (#36386 nbody / sqrt).
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $existing = $this->context->namedVariableBindings[$resolved];
                if (
                    Variable::TYPE_NATIVE_LONG === $existing->type
                    || Variable::TYPE_NATIVE_BOOL === $existing->type
                    || Variable::TYPE_NATIVE_DOUBLE === $existing->type
                    || (
                        Variable::TYPE_VALUE === $existing->type
                        && Variable::KIND_VARIABLE === $existing->kind
                    )
                ) {
                    return null;
                }
            }

            return $this->ensureScriptGlobalForRuntimeRead($op, $name, $skipUndefGuard);
        }
        if ($block->declaresGlobalName($name) || isset($this->context->jitImportedGlobalNames[$name])) {
            return $this->ensureScriptGlobalForRuntimeRead($op, $name, $skipUndefGuard);
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (
            isset($this->context->namedVariableBindings[$resolved])
            && $this->context->namedVariableBindings[$resolved]->functionStaticGlobal
        ) {
            // Nested-function `static $s` is a module box, not $GLOBALS. Echo must
            // read that binding — ensureScriptGlobal() allocated a second empty slot (#31966).
            return $this->context->namedVariableBindings[$resolved];
        }

        return null;
    }

    /**
     * Module-global script variable with ZEND_CHECK_UNDEFINED_VAR before read (#10360, #36081).
     *
     * {main} locals and `global $name` imports share ensureScriptGlobal() heap boxes; reads
     * previously skipped UndefinedVariableHelper when echoScriptGlobalName was set (#23842).
     */
    /**
     * AssignOp peephole fuses CONCAT+ASSIGN in-place (#16281); echo must read that CV slot,
     * not the {main} script-global sidecar the echo opcode names (#36366 / p16).
     */
    private function jitBlockHasInBlockConcatToSlot(Block $block, int $slot): bool
    {
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_CONCAT === $prior->type && null !== $prior->arg1 && (int) $prior->arg1 === $slot) {
                return true;
            }
        }

        return false;
    }

    private function ensureScriptGlobalForRuntimeRead(
        Operand $op,
        string $name,
        bool $skipUndefGuard = false
    ): JIT\Variable {
        $global = $this->context->ensureScriptGlobal($name);
        if (!$skipUndefGuard) {
            JIT\UndefinedVariableHelper::guardBeforeScriptGlobalName($this->context, $name);
        }

        return $global;
    }

    /** Resolve a CV name when the assign/echo slot wraps a Temporary without OperandName (#36081). */
    private function resolveLocalNameForOperand(Block $block, Operand $op, int $slot): ?string
    {
        $name = JIT\OperandName::resolve($op);
        if (null !== $name && '' !== $name) {
            return $name;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) !== $slot) {
                continue;
            }
            $name = JIT\OperandName::resolve($scopeOp);
            if (null !== $name && '' !== $name) {
                return $name;
            }
        }

        return null;
    }

    /**
     * Inlined {main} includes inherit caller locals — never route those names through the
     * standalone script-global sidecar (#866, coalesce_then_inherited_local).
     */
    private function shouldDeferScriptGlobalForInlineIncludeBinding(
        string $name,
        ?Operand $op = null,
        ?Block $block = null
    ): bool {
        if ($this->context->inlineIncludeDepth <= 0) {
            return false;
        }
        // Inlined {main} units share the caller's LLVM function — locals live in
        // include-binding allocas, not standalone script-global sidecars (#866).
        $block ??= $this->context->jitEnclosingBlock ?? $this->context->jitFunctionRootBlock;
        if (null !== $block && $block->isMainScript()) {
            return !\PHPCompiler\Web\Superglobals::isSuperglobalName($name);
        }
        if (JIT\IncludeBindingEmitHelper::refreshFrameDeclaresName($this->context, $name)) {
            return true;
        }
        if (null !== $op && $this->context->hasVariableOp($op)) {
            return $this->context->getVariableFromOp($op)->includeBinding;
        }

        return false;
    }

    /** Scope operand for value reads that may emit undefined-variable E_WARNING (#10358, #10360, #26147). */
    private function variableFromOpForRuntimeRead(Operand $op): JIT\Variable
    {
        $var = $this->context->getVariableFromOp($op);
        JIT\UndefinedVariableHelper::guardBeforeRuntimeRead($this->context, $op, $var);
        $var = $this->ensureNamedNativeLongLocalAlloca($op, $var);

        return $var;
    }

    private function markScopeVariableAssignedIfTracked(Operand $resultOp, JIT\Variable $result): void
    {
        JIT\UndefinedVariableHelper::markAssigned($this->context, $resultOp, $result);
    }

    /**
     * Prefer active foreach by-ref lvalues over {main} script-global slots (#4364).
     *
     * Foreach/try phi merges can drop {@see JIT\Variable::$functionStaticGlobal} on `global $name`
     * lvalues after an early return (src/llvm-env.php, issue #16828).
     */
    private function resolveAssignLvalue(Operand $resultOp): JIT\Variable
    {
        $block = $this->context->jitEnclosingBlock;
        if (null !== $block && null !== $block->func) {
            $slot = $block->slotForOperand($resultOp);
            if (null !== $slot) {
                foreach ($block->func->params as $paramIdx => $param) {
                    if (!isset($block->paramByRef[$paramIdx])) {
                        continue;
                    }
                    if ($block->slotForOperand($param->result) !== $slot) {
                        continue;
                    }
                    if (!$this->context->hasVariableOp($param->result)) {
                        continue;
                    }
                    $paramVar = $this->context->getVariableFromOp($param->result);
                    if (
                        null !== $paramVar->valueBoxAliasPtr
                        || $paramVar->borrowedValueEntry
                    ) {
                        $this->context->scope->variables[$resultOp] = $paramVar;

                        return $paramVar;
                    }
                }
            }
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                if (
                    null !== $bound->valueBoxAliasPtr
                    || $bound->borrowedValueEntry
                    || null !== $bound->foreachByRefPackedArm
                    || null !== $bound->objectPropertySlot
                    || $bound->assignRefLvalueAlias
                ) {
                    $this->context->scope->variables[$resultOp] = $bound;

                    return $bound;
                }
            }
        }
        $result = $this->context->getVariableFromOp($resultOp);
        if (null !== $result->foreachByRefPackedArm || $result->borrowedValueEntry) {
            return $result;
        }
        if (null !== $name && '' !== $name) {
            $resolved = $this->context->resolveRefAliasName($name);
            if (isset($this->context->namedVariableBindings[$resolved])) {
                $bound = $this->context->namedVariableBindings[$resolved];
                if ($bound->functionStaticGlobal || null !== $bound->staticPropertyGlobal) {
                    $this->context->scope->variables[$resultOp] = $bound;

                    return $bound;
                }
                if (null !== $bound->foreachByRefPackedArm || $bound->borrowedValueEntry) {
                    $this->context->scope->variables[$resultOp] = $bound;

                    return $bound;
                }
            }
            $block = $this->context->jitEnclosingBlock;
            if (null !== $block) {
                $resolvedBinding = $this->context->resolveRefAliasName($name);
                if (
                    isset($this->context->namedVariableBindings[$resolvedBinding])
                    && (
                        $this->context->namedVariableBindings[$resolvedBinding]->functionStaticGlobal
                        || null !== $this->context->namedVariableBindings[$resolvedBinding]->staticPropertyGlobal
                    )
                ) {
                    $global = $this->context->namedVariableBindings[$resolvedBinding];
                    $this->context->scope->variables[$resultOp] = $global;

                    return $global;
                }
            }
            $block = $this->context->jitEnclosingBlock;
            if (null !== $block && (
                $block->declaresGlobalName($name)
                || isset($this->context->jitImportedGlobalNames[$name])
            )) {
                $global = $this->context->ensureScriptGlobal($name);
                $this->context->bindVariableByName($name, $global);
                $this->context->scope->variables[$resultOp] = $global;

                return $global;
            }
        }
        if (null === $name || '' === $name || !$result->functionStaticGlobal) {
            $recovered = $this->recoverScriptGlobalAssignLvalueBySlot($resultOp, $result);
            if (null !== $recovered) {
                return $recovered;
            }

            return $result;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (isset($this->context->namedVariableBindings[$resolved])) {
            $bound = $this->context->namedVariableBindings[$resolved];
            if (null !== $bound->foreachByRefPackedArm || $bound->borrowedValueEntry) {
                $this->context->scope->variables[$resultOp] = $bound;

                return $bound;
            }
        }
        foreach ($this->context->scope->variables as $scopeOp) {
            if (!$scopeOp instanceof Operand) {
                continue;
            }
            $scopeName = JIT\OperandName::resolve($scopeOp);
            if (null === $scopeName || $resolved !== $this->context->resolveRefAliasName($scopeName)) {
                continue;
            }
            $scopeVar = $this->context->scope->variables[$scopeOp];
            if (null !== $scopeVar->foreachByRefPackedArm || $scopeVar->borrowedValueEntry) {
                $this->context->scope->variables[$resultOp] = $scopeVar;

                return $scopeVar;
            }
        }

        return $result;
    }

    /**
     * Foreach/try phi operands may lose the variable name while keeping the global slot (#16828).
     */
    private function recoverScriptGlobalAssignLvalueBySlot(Operand $resultOp, JIT\Variable $result): ?JIT\Variable
    {
        $block = $this->context->jitEnclosingBlock;
        if (null === $block) {
            return null;
        }
        $slot = $block->slotForOperand($resultOp);
        if (null === $slot) {
            return null;
        }
        foreach ($block->scopedOperands() as $scopeOp) {
            if ($block->slotForOperand($scopeOp) !== $slot) {
                continue;
            }
            if ($this->context->scope->variables->contains($scopeOp)) {
                $scopeVar = $this->context->scope->variables[$scopeOp];
                if ($scopeVar->functionStaticGlobal) {
                    $this->context->scope->variables[$resultOp] = $scopeVar;

                    return $scopeVar;
                }
            }
            $scopeName = JIT\OperandName::resolve($scopeOp);
            if (null === $scopeName || '' === $scopeName) {
                continue;
            }
            if (
                !$block->declaresGlobalName($scopeName)
                && !isset($this->context->jitImportedGlobalNames[$scopeName])
            ) {
                continue;
            }
            $global = $this->context->ensureScriptGlobal($scopeName);
            $this->context->bindVariableByName($scopeName, $global);
            $this->context->scope->variables[$resultOp] = $global;

            return $global;
        }

        return null;
    }

    private function resolveScriptGlobalAssignTarget(Operand $resultOp, JIT\Variable $result): ?JIT\Variable
    {
        if ($result->functionStaticGlobal) {
            return $result;
        }
        $name = JIT\OperandName::resolve($resultOp);
        if (null === $name || '' === $name) {
            $block = $this->context->jitEnclosingBlock;
            if (null !== $block) {
                $slot = $block->slotForOperand($resultOp);
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
        if (null === $name || '' === $name || \PHPCompiler\Web\Superglobals::isSuperglobalName($name)) {
            return null;
        }
        $resolved = $this->context->resolveRefAliasName($name);
        if (
            isset($this->context->namedVariableBindings[$resolved])
            && $this->context->namedVariableBindings[$resolved]->functionStaticGlobal
        ) {
            return $this->context->namedVariableBindings[$resolved];
        }
        $root = $this->context->jitFunctionRootBlock ?? $this->context->jitEnclosingBlock;
        if (isset($this->context->jitImportedGlobalNames[$name])) {
            $global = $this->context->ensureScriptGlobal($name);
            $this->context->bindVariableByName($name, $global);

            return $global;
        }
        if (null !== $root && $root->declaresGlobalName($name)) {
            $global = $this->context->ensureScriptGlobal($name);
            $this->context->bindVariableByName($name, $global);

            return $global;
        }

        return null;
    }
}
