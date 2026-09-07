<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\Variable;
use PHPCompiler\ext\standard\VmEval;

/**
 * Frame activation, $this binding, and include/eval scope for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code resumeEphemeralCallerFrame} through
 * {@code guardUnboundThisRead} (php-src Zend/zend_execute.c ZEND_INCLUDE_OR_EVAL /
 * FETCH_THIS; Zend/zend_vm_def.h inherits EX(This); goto/label frame reuse #1228).
 * Concern trait — same namespace as parent so relative Frame helpers resolve.
 * Move-only; no new C ABI.
 */
trait FrameActivationThisAndIncludeScope
{
    /**
     * Resume the caller after an ephemeral child (constructor, etc.) finishes.
     */
    private function resumeEphemeralCallerFrame(Frame $child): Frame
    {
        $this->markObjectConstructedIfLeavingConstruct($child);
        $caller = $child->parent;
        if (null === $caller) {
            return $child;
        }
        $caller->call = null;
        $this->clearOutgoingCallState($caller);
        $this->restorePendingOutboundCallAfterInlineNew($caller);
        $this->releaseFrameObjectRefs($child);

        return $caller;
    }

    /**
     * Goto / label back-edges reuse the innermost frame for the target block (#1228).
     * php-cfg lowers `if (cond) goto L` as JumpIf to the label block; naive getFrame()
     * nests a new frame per iteration and never terminates on merge blocks.
     */
    /**
     * Runtime-init function static: continue block return must not resume the entry
     * frame at TYPE_FUNCTION_STATIC_INIT_STORE (#7097, property hook dispatch).
     */
    private function isFunctionStaticInitContinueReturn(Frame $continueFrame): bool
    {
        $entry = $continueFrame->parent;
        if (null === $entry || $entry->pos < 1) {
            return false;
        }
        $prev = $entry->block->opCodes[$entry->pos - 1] ?? null;
        if (null === $prev || OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED !== $prev->type) {
            return false;
        }

        return $prev->block1 === $continueFrame->block;
    }

    private function frameForBranch(Frame $frame, Block $target): Frame
    {
        if ($target === $frame->block) {
            while (null !== $frame->parent && $frame->parent->block === $target) {
                $frame = $frame->parent;
            }
            $frame->pos = 0;

            return $frame;
        }

        // Cross-block loop back-edges must reuse the header frame — otherwise every iteration
        // chains a new parent Frame and getFrame walks grow without bound (#1228, #15906, #36148).
        // Recursive re-entry shares Block objects across activations — only reuse frames within
        // the current call (#23472 g06_nested_recursion OOM).
        $targetFunc = $target->func;
        $activationEntry = $this->activationEntryFrame($frame, $targetFunc);

        if (
            null !== $frame->parent
            && $frame->parent->block === $target
            && (null === $activationEntry || $this->frameInActivation($activationEntry, $frame->parent))
        ) {
            $frame->parent->pos = 0;

            return $frame->parent;
        }

        for ($ancestor = $frame->parent; null !== $ancestor; $ancestor = $ancestor->parent) {
            if (null !== $activationEntry && !$this->frameInActivation($activationEntry, $ancestor)) {
                break;
            }
            if ($ancestor->block === $target) {
                $ancestor->pos = 0;

                return $ancestor;
            }
            if (
                null !== $targetFunc
                && null !== $ancestor->block
                && null !== $ancestor->block->func
                && $ancestor->block->func !== $targetFunc
            ) {
                break;
            }
        }

        return $target->getFrame($this->context, $frame);
    }

    /** Innermost entry-block frame for the active call of $func, if any. */
    private function activationEntryFrame(Frame $frame, ?\PHPCfg\Func $func): ?Frame
    {
        if (null === $func || null === $func->cfg) {
            return null;
        }
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if (null === $f->block || $f->block->func !== $func) {
                break;
            }
            if (null !== $f->block->orig && $f->block->orig === $func->cfg) {
                return $f;
            }
        }

        return null;
    }

    /** True when $candidate is $activationEntry or a descendant frame in the same activation. */
    private function frameInActivation(Frame $activationEntry, Frame $candidate): bool
    {
        for ($f = $candidate; null !== $f; $f = $f->parent) {
            if ($f === $activationEntry) {
                return true;
            }
        }

        return false;
    }

    /** Zend compile-time fatal if $this is written; runtime guard when compile missed (#4865). */
    private function dispatchThisReassignFatalIfNeeded(Frame $frame, int $writeSlot): ?Frame
    {
        $func = $frame->block->func;
        if (null === $func || null === $func->class) {
            return null;
        }
        $thisIdx = $frame->block->slotIndexForVariableName('this');
        if (null === $thisIdx || $writeSlot !== $thisIdx) {
            return null;
        }

        return $this->dispatchVmError('Cannot re-assign $this', $frame);
    }

    /**
     * isset($this) / empty($this) in static or non-object scope — false / true without Error (#5411).
     * File scope ({main}), plain functions, and static methods: $this is never bound (#31728).
     */
    private function isUnboundThisSlot(Frame $frame, int $slot): bool
    {
        $thisIdx = $frame->block->slotIndexForVariableName('this');
        if (null === $thisIdx || $thisIdx !== $slot) {
            return false;
        }
        $func = $frame->block->func;
        // No frame function (eval/include {main}) — bound only when EX(This) was inherited (#31902).
        if (null === $func) {
            if (isset($frame->scope[$thisIdx])) {
                $var = $frame->scope[$thisIdx]->resolveIndirect();
                if (Variable::TYPE_OBJECT === $var->type) {
                    return false;
                }
            }

            return true;
        }
        // Static methods and static closures never have $this (zend_closures.c / #23704).
        if ((($func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            return true;
        }
        // Closures only have $this when auto-bound / bindTo supplied an object.
        // Scope class may still be set (created inside a method) while $this is NULL —
        // static-method-created free closures (#28814) and top-level arrows (#10558).
        // TYPE_UNDEFINED in scope must not count as bound (getFrame leaves that sentinel).
        if (((int) ($func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0) {
            return !$this->closureFrameHasBoundThis($frame, $thisIdx);
        }
        // Instance method: unbound when $this was never installed in scope.
        if (null !== $func->class) {
            return !isset($frame->scope[$thisIdx]);
        }
        // {main} / plain function — $this is never in object context (php-src FETCH_THIS)
        // unless eval/include inherited EX(This) from an instance caller (#31902, #31903).
        if (isset($frame->scope[$thisIdx])) {
            $var = $frame->scope[$thisIdx]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $var->type) {
                return false;
            }
        }

        return true;
    }

    /**
     * ZEND_INCLUDE_OR_EVAL copies EX(This) into the included/eval {main} frame (#31902, #31903).
     *
     * php-src: Zend/zend_execute.c ZEND_INCLUDE_OR_EVAL; Zend/zend_vm_def.h inherits EX(This).
     */
    private function inheritIncludeThis(Frame $included, Frame $caller): void
    {
        $thisIdx = $included->block->slotIndexForVariableName('this');
        if (null === $thisIdx) {
            return;
        }
        $inherited = self::callerThisIfBound($caller);
        if (null === $inherited) {
            return;
        }
        if (!isset($included->scope[$thisIdx])) {
            $included->scope[$thisIdx] = new Variable();
        }
        $included->scope[$thisIdx]->copyFrom($inherited);
    }

    /**
     * zend_eval_string copies func->common.scope (self) and called_scope (static) (#31912).
     *
     * php-src: Zend/zend_execute_API.c zend_eval_string; Zend/zend_execute.c ZEND_INCLUDE_OR_EVAL.
     */
    private function inheritEvalClassScope(Frame $eval, Frame $caller): void
    {
        $declaring = VmEval::declaringClassFromFrame($caller);
        if (null !== $declaring && '' !== $declaring) {
            $eval->scopeClass = $declaring;
        }
        if (null === $eval->calledClass || '' === $eval->calledClass) {
            if (null !== $caller->calledClass && '' !== $caller->calledClass) {
                $eval->calledClass = $caller->calledClass;
            } elseif (null !== $declaring && '' !== $declaring) {
                $eval->calledClass = $declaring;
            }
        }
    }

    /**
     * ZEND_INCLUDE_OR_EVAL copies caller called_scope into the included {main} frame (#31913).
     *
     * php-src: Zend/zend_execute.c / zend_vm_def.h — self/static/parent in an included file
     * bind to the runtime caller class, not a compile-time global-scope reject.
     */
    private function inheritIncludeClassScope(Frame $included, Frame $caller): void
    {
        if (null !== $included->calledClass && '' !== $included->calledClass) {
            return;
        }
        $scope = self::includeCallerClassScopeLc($caller);
        if (null !== $scope) {
            $included->calledClass = $scope;
        }
    }

    /**
     * Late-static / self scope class (lowercase) of an include/require caller frame.
     */
    private static function includeCallerClassScopeLc(Frame $caller): ?string
    {
        if (null !== $caller->calledClass && '' !== $caller->calledClass) {
            return $caller->calledClass;
        }
        if (null !== $caller->block && null !== $caller->block->func && null !== $caller->block->func->class) {
            return strtolower(ltrim($caller->block->func->class->value, '\\'));
        }
        $boundThis = self::callerThisIfBound($caller);
        if (null !== $boundThis && Variable::TYPE_OBJECT === $boundThis->type) {
            return strtolower($boundThis->toObject()->class->name);
        }

        return null;
    }
    private static function callerThisIfBound(Frame $caller): ?Variable
    {
        $func = null !== $caller->block ? $caller->block->func : null;
        if (null !== $func && (($func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            return null;
        }
        if (null !== $caller->pendingClosureInvoke && null !== $caller->pendingClosureInvoke->boundThis) {
            $bound = $caller->pendingClosureInvoke->boundThis->resolveIndirect();
            if (Variable::TYPE_OBJECT === $bound->type) {
                return $bound;
            }
        }
        if (null !== $caller->closureCall && null !== $caller->closureCall->boundThis) {
            $bound = $caller->closureCall->boundThis->resolveIndirect();
            if (Variable::TYPE_OBJECT === $bound->type) {
                return $bound;
            }
        }
        if (null !== $caller->block) {
            $idx = $caller->block->slotIndexForVariableName('this');
            if (null !== $idx && isset($caller->scope[$idx])) {
                $var = $caller->scope[$idx]->resolveIndirect();
                if (Variable::TYPE_OBJECT === $var->type) {
                    return $var;
                }
            }
        }
        // Instance method whose body never mentioned $this — receiver is calledArgs[0].
        if (
            null !== $func
            && null !== $func->class
            && isset($caller->calledArgs[0])
        ) {
            $arg0 = $caller->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $arg0->type) {
                return $arg0;
            }
        }

        return null;
    }

    /** True when a closure invoke has a bound object for $this (auto-bind / bindTo). */
    private function closureFrameHasBoundThis(Frame $frame, int $thisIdx): bool
    {
        if (isset($frame->scope[$thisIdx])) {
            $var = $frame->scope[$thisIdx]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $var->type) {
                return true;
            }
        }
        $closureState = $frame->closureCall ?? $frame->pendingClosureInvoke;
        if (null !== $closureState && null !== $closureState->boundThis) {
            return true;
        }

        return false;
    }

    /** Runtime Error when $this is evaluated outside object context (not isset/empty). */
    private function guardUnboundThisRead(Frame $frame, int $slot): ?Frame
    {
        if (!$this->isUnboundThisSlot($frame, $slot)) {
            return null;
        }

        return $this->dispatchVmError('Using $this when not in object context', $frame);
    }

}
