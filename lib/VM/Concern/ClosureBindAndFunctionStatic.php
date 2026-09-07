<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

/**
 * Closure use()/invoke binding + function-static storage bridges (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code bindClosureCaptures} through
 * {@code applyClosureBinding} (php-src Zend/zend_closures.c capture binding /
 * static_variables; Zend/zend_execute.c function statics). Concern trait — same
 * namespace as parent so relative Frame / Block / OpCode helpers resolve.
 * Move-only; no new C ABI.
 */
trait ClosureBindAndFunctionStatic
{
    /**
     * @param list<array{name: string, slot: int, byRef: bool}> $captureSpecs
     *
     * @return list<array{slot: int, var: Variable, byRef: bool}>
     */
    protected function bindClosureCaptures(Frame $frame, array $captureSpecs): array
    {
        $captures = [];
        foreach ($captureSpecs as $spec) {
            $src = $this->resolveClosureCaptureSource($spec['name'], $frame);
            $stored = new Variable();
            if (null === $src) {
                $stored->null();
            } elseif ($spec['byRef']) {
                $stored->indirect($src->byRefTarget());
            } else {
                $stored->copyFrom($src->resolveIndirect());
            }
            $captures[] = [
                'slot' => $spec['slot'],
                'var' => $stored,
                'byRef' => $spec['byRef'],
            ];
        }

        return $captures;
    }

    /**
     * Parent CV for closure `use` — include declared-but-unassigned slots for self-referential
     * `use (&$fn)` on `$fn = function () use (&$fn)` (Zend/zend_closures.c, #17089).
     */
    protected function resolveClosureCaptureSource(string $name, Frame $frame): ?Variable
    {
        $src = Block::findVariableInParentFramesByName($name, $frame);
        if (null !== $src) {
            return $src;
        }
        $blockScriptGlobals = null !== $frame->block && $frame->block->blocksScriptGlobalInheritance();
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if (
                $blockScriptGlobals
                && null !== $f->block
                && $f->block->isMainScript()
            ) {
                break;
            }
            if (null === $f->block) {
                continue;
            }
            $idx = $f->block->slotIndexForVariableName($name);
            if (null === $idx) {
                continue;
            }
            if (!isset($f->scope[$idx])) {
                $f->scope[$idx] = new Variable();
            }

            return $f->scope[$idx];
        }

        return null;
    }

    /** True when $slot is a by-ref closure `use` capture in this frame (#17089). */
    private function frameScopeSlotIsClosureByRefCapture(Frame $frame, int $slot): bool
    {
        if (isset($frame->block->closureCaptureByRef[$slot])) {
            return true;
        }
        $state = $frame->closureCall;
        if (null === $state) {
            return false;
        }
        foreach ($state->captures as $capture) {
            if ($capture['byRef'] && (int) $capture['slot'] === $slot) {
                return true;
            }
        }

        return false;
    }

    protected function resolvePendingClosureState(Frame $frame): ?ClosureState
    {
        if (null !== $frame->pendingClosureInvoke) {
            return $frame->pendingClosureInvoke;
        }
        if (null !== $frame->closureCall) {
            return $frame->closureCall;
        }
        if (null !== $frame->closureCallableSlot && isset($frame->scope[$frame->closureCallableSlot])) {
            $callable = $frame->scope[$frame->closureCallableSlot]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $callable->type) {
                return $callable->toObject()->closureState;
            }
        }

        return null;
    }

    protected function frameUsesClosureStaticStorage(Frame $frame): bool
    {
        if (null === $frame->closureCall) {
            return false;
        }
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }

        // Per-closure statics only inside closure bodies; nested user-function calls from
        // a closure must not inherit the caller's ClosureState (#11451, Zend/zend_execute.c).
        return (($func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0;
    }

    /**
     * Enclosing-function statics use {@see Context} keys {@code func\0var}; closure-body
     * statics use bare names (Zend/zend_closures.c static_variables, issue #4872).
     * Captured parent statics via {@code use (&$n)} keep the context key (#14077).
     */
    protected function functionStaticUsesContextStorage(string $storageKey): bool
    {
        return str_contains($storageKey, "\0");
    }

    protected function ensureFunctionStaticForFrame(Frame $frame, string $storageKey): Variable
    {
        if (
            $this->frameUsesClosureStaticStorage($frame)
            && !$this->functionStaticUsesContextStorage($storageKey)
        ) {
            return $frame->closureCall->ensureStatic($storageKey);
        }

        return $this->context->ensureFunctionStatic($storageKey);
    }

    protected function isFunctionStaticInitializedForFrame(Frame $frame, string $storageKey): bool
    {
        if (
            $this->frameUsesClosureStaticStorage($frame)
            && !$this->functionStaticUsesContextStorage($storageKey)
        ) {
            return $frame->closureCall->isStaticInitialized($storageKey);
        }

        return $this->context->isFunctionStaticInitialized($storageKey);
    }

    protected function markFunctionStaticInitializedForFrame(Frame $frame, string $storageKey): void
    {
        if (
            $this->frameUsesClosureStaticStorage($frame)
            && !$this->functionStaticUsesContextStorage($storageKey)
        ) {
            $frame->closureCall->markStaticInitialized($storageKey);

            return;
        }
        $this->context->markFunctionStaticInitialized($storageKey);
    }

    protected function applyFunctionStaticTypeMetadata(Variable $storage, Frame $frame, OpCode $op): void
    {
        $resolved = $storage->resolveIndirect();
        // Always mark storage (typed or not) so frame teardown skips releaseRef (#28039).
        $resolved->functionStaticStorage = true;
        if (null !== $op->functionStaticVarName && '' !== $op->functionStaticVarName) {
            $resolved->functionStaticVarName = $op->functionStaticVarName;
        }
        if (null === $op->functionStaticTypeSlot || !isset($frame->block->constants[$op->functionStaticTypeSlot])) {
            return;
        }
        $proto = $frame->block->constants[$op->functionStaticTypeSlot];
        $resolved->typeConstraint = $proto->typeConstraint;
        $resolved->classConstraint = $proto->classConstraint;
        $resolved->literalBoolType = $proto->literalBoolType;
        $resolved->unionTypeConstraints = $proto->unionTypeConstraints;
        $resolved->declaredTypeLabel = $proto->declaredTypeLabel;
        $resolved->genericArrayTypeSpec = $proto->genericArrayTypeSpec;
        $resolved->dnfArms = $proto->dnfArms;
    }

    protected function enforceFunctionStaticWrite(
        Variable $storage,
        Frame $frame,
        ?string $varName
    ): ?Frame {
        if (null === $storage->resolveIndirect()->typeConstraint && null === $storage->resolveIndirect()->dnfArms) {
            return null;
        }
        if (null !== $varName && '' !== $varName) {
            $storage->resolveIndirect()->functionStaticVarName = $varName;
        }
        $strict = null !== $frame->parent
            ? $frame->parent->block->strictTypes
            : $frame->block->strictTypes;
        $probe = new Variable();
        $probe->indirect($storage);
        try {
            TypeCheck::coerceFunctionStaticWrite($probe, $strict);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        }

        return null;
    }

    protected function bindClosureCallCaptures(Frame $callee, ?ClosureState $closureState): void
    {
        if (null === $closureState || [] === $closureState->captures) {
            return;
        }
        foreach ($closureState->captures as $capture) {
            $slot = (int) $capture['slot'];
            $dest = $this->scopeSlot($callee, $slot);
            if ($capture['byRef']) {
                $dest->indirect($capture['var']->byRefTarget());
            } else {
                $dest->copyFrom($capture['var']);
            }
            // Captured CVs are bound at closure entry — not undefined locals (#10304, #10358).
            $this->markScopeSlotInitialized($callee, $slot);
        }
    }

    protected function initClosureCall(Frame $frame, ClosureState $state): void
    {
        if (null !== $state->methodName && null !== $state->methodReceiver) {
            // Callee LSB is applied in applyClosureBinding — do not write the FCC class onto
            // the caller frame (that poisoned later static:: / : static in the unit, #32083).
            $this->initMethodCall($frame, $state->methodReceiver, $state->methodName);
            $frame->closureCall = null;
            $frame->pendingClosureInvoke = $state;

            return;
        }
        // Static magic fake closure: methodName + __callStatic, no receiver (#25757).
        if (
            null !== $state->methodName
            && '' !== $state->methodName
            && null !== $state->wrappedFunc
            && null === $state->methodReceiver
        ) {
            $frame->magicCallMethodName = $state->methodName;
            $frame->call = $state->wrappedFunc;
            $frame->closureCall = null;
            $frame->pendingClosureInvoke = $state;
            $frame->callArgs = [];
            $frame->callArgEntries = [];
            $frame->builtinCalleeQualifiedMethod = null;

            return;
        }
        if (null !== $state->wrappedFunc) {
            $frame->call = $state->wrappedFunc;
            $frame->closureCall = null;
            $frame->pendingClosureInvoke = $state;
            // Scoped parent/self FCC (#17655/#26630) and fromCallable instance wrappers clear
            // methodReceiver and call wrappedFunc directly. Instance methods still need $this
            // as callArgs[0] so user args land at ARG_RECV indices 1..n (#27834).
            $frame->callArgs = $this->wrappedFuncInstanceThisPrefix($state);
            $frame->callArgEntries = [];
            $frame->builtinCalleeQualifiedMethod = null;

            return;
        }
        $frame->call = $state->func;
        if (
            null === $frame->closureCall
            || null === $frame->block?->func
            || (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) === 0
        ) {
            $frame->closureCall = $state;
        }
        $frame->pendingClosureInvoke = $state;
        $frame->callArgs = [];
        $frame->callArgEntries = [];
        $frame->builtinCalleeQualifiedMethod = null;
    }

    /**
     * $this prefix for wrappedFunc instance-method FCC / fromCallable (#27834).
     *
     * @return list<Variable>
     */
    private function wrappedFuncInstanceThisPrefix(ClosureState $state): array
    {
        if (null === $state->boundThis || null === $state->wrappedFunc) {
            return [];
        }
        if ($this->methodIsStatic($state->wrappedFunc)) {
            return [];
        }
        $wrapped = $state->wrappedFunc;
        if ($wrapped instanceof Func\PHP) {
            $decl = $wrapped->block->func ?? null;
            if (null === $decl || null === $decl->class) {
                return [];
            }
        }

        return [$state->boundThis];
    }

    protected function applyClosureBinding(Frame $callee, ?ClosureState $closureState): void
    {
        $this->bindClosureCallCaptures($callee, $closureState);
        if (null === $closureState) {
            return;
        }
        $callee->closureCall = $closureState;
        if (null !== $closureState->boundThis) {
            $thisIdx = $closureState->func->block->slotIndexForVariableName('this');
            if (null !== $thisIdx) {
                if (!isset($callee->scope[$thisIdx])) {
                    $callee->scope[$thisIdx] = new Variable();
                }
                $boundThis = $closureState->boundThis;
                if (EnumCaseSupport::isEnumCaseVariable($boundThis)) {
                    $boundThis = EnumCaseSupport::materializeConstantValue($this->context, $boundThis);
                }
                $callee->scope[$thisIdx]->copyFrom($boundThis);
            }
        }
        $calledScope = $this->closureCalledScopeClass($closureState);
        if (null !== $calledScope && '' !== $calledScope) {
            $callee->calledClass = $calledScope;
        }
    }
}
