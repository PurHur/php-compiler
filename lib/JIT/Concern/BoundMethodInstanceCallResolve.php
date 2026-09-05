<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;
use PHPLLVM;

/**
 * Bound-method FCC / static-array callable fold (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code queuedFuncIsClassMethodAlias}
 * through {@code tryInitStaticArrayCallableDirect} so the hub shrinks toward
 * split-TU iterability under the size-budget ratchet. Sibling of
 * NestedVmHelperAndThisResolve (#36806), which owns receiver/$this resolve.
 *
 * php-src: Zend/zend_execute.c ZEND_INIT_DYNAMIC_CALL / ZEND_INIT_METHOD_CALL;
 * FCC/array-callable dispatch in Zend/zend_closures.c — move-only Concern
 * extract; no new C ABI and no opcode/IR shape change.
 */
trait BoundMethodInstanceCallResolve
{
    /**
     * True when a queued LLVM function is registered as Class::method (NestedJIT #16075).
     */
    private function queuedFuncIsClassMethodAlias(PHPLLVM\Value $llvmFunc, Block $cfgBlock): bool
    {
        $methodLc = strtolower($cfgBlock->func->name);
        foreach ($this->context->functions as $name => $candidate) {
            if ($candidate !== $llvmFunc || !str_contains($name, '::')) {
                continue;
            }
            [, $methodPart] = explode('::', $name, 2);
            if (strtolower($methodPart) === $methodLc) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fold `$obj->method(...)` FCC array callables to direct instance dispatch (#4040).
     */
    private function tryInitBoundMethodFccDirect(Block $block, ?int $calleeSlot): bool
    {
        if (null === $calleeSlot) {
            return false;
        }
        $methodLc = JIT\BoundMethodCallableHelper::resolveMethodLcFromCalleeSlot($block, $calleeSlot);
        if (null === $methodLc) {
            return false;
        }
        $receiverOp = JIT\BoundMethodCallableHelper::resolveBoundMethodReceiverOperand($block, $calleeSlot);
        if (null === $receiverOp) {
            return false;
        }
        if (null === $receiverOp->type || Type::TYPE_OBJECT !== $receiverOp->type->type) {
            return false;
        }
        $this->initJitMethodCall($block, $receiverOp, $methodLc);

        return true;
    }

    /**
     * Fold `['Class','method']()` array callables to INIT_STATIC_METHOD_CALL (#32299).
     *
     * RuntimeVariableFunction only dispatches string function names; an array callee
     * previously emitted abort() (rc=134). php-src: Zend/zend_execute.c ZEND_INIT_DYNAMIC_CALL.
     */
    private function tryInitStaticArrayCallableDirect(Block $block, ?int $calleeSlot): bool
    {
        if (null === $calleeSlot) {
            return false;
        }
        $slots = VM\VmBoundMethodCallable::resolveStaticArrayCallableSlots($block, $calleeSlot);
        if (null === $slots) {
            return false;
        }
        $this->initJitStaticCall($slots[2], $slots[0], $slots[1], false, true);

        return true;
    }
}
