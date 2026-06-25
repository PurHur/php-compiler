<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\VM\VmBoundMethodCallable;

/**
 * JIT trampoline for bound-method first-class callables `[object, method]` (#3566, #4040, #10185).
 *
 * SSOT: {@see \PHPCompiler\VM\VmBoundMethodCallable}
 */
final class BoundMethodCallableHelper
{
    public static function isBoundMethodArrayCallee(Operand $op, Variable $var): bool
    {
        return VmBoundMethodCallable::isBoundMethodArrayCallee($op, $var);
    }

    public static function resolveMethodLcFromCalleeSlot(Block $block, ?int $calleeSlot): ?string
    {
        return VmBoundMethodCallable::resolveMethodLcFromCalleeSlot($block, $calleeSlot);
    }

    public static function resolveBoundMethodReceiverOperand(Block $block, int $calleeSlot): ?Operand
    {
        return VmBoundMethodCallable::resolveBoundMethodReceiverOperand($block, $calleeSlot);
    }

    public static function resolveBoundMethodReceiverClassName(Block $block, int $calleeSlot): ?string
    {
        return VmBoundMethodCallable::resolveBoundMethodReceiverClassName($block, $calleeSlot);
    }

    public static function resolveInvokableObjectReceiverOperand(Block $block, int $slot): ?Operand
    {
        return VmBoundMethodCallable::resolveInvokableObjectReceiverOperand($block, $slot);
    }

    public static function resolveInvokableObjectClassName(Block $block, int $slot): ?string
    {
        return VmBoundMethodCallable::resolveInvokableObjectClassName($block, $slot);
    }

    public static function resolveBoundMethodArrayRootSlot(
        Block $block,
        int $slot,
        array &$visited = []
    ): ?int {
        return VmBoundMethodCallable::resolveBoundMethodArrayRootSlot($block, $slot, $visited);
    }
}
