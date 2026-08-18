<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\OpCode;
use PHPTypes\Type;

/**
 * SSOT for bound-method first-class callable `[object, method]` compile-time resolution (#3566, #4040, #10185).
 *
 * php-src: Zend/zend_compile.c — zend_compile_callable
 * php-src: Zend/zend_execute.c — ZEND_INIT_FCALL bound method path
 */
final class VmBoundMethodCallable
{
    public static function isBoundMethodArrayCallee(Operand $op, JitVariable $var): bool
    {
        if (null !== $op->type && Type::TYPE_ARRAY === $op->type->type) {
            return true;
        }
        if (0 !== ($var->type & JitVariable::TYPE_HASHTABLE)) {
            return true;
        }
        if (JitVariable::TYPE_VALUE === $var->type && null !== $var->valueBoxHashtable) {
            return true;
        }

        return false;
    }

    public static function resolveMethodLcFromCalleeSlot(Block $block, ?int $calleeSlot): ?string
    {
        if (null === $calleeSlot) {
            return null;
        }
        $arraySlot = self::resolveBoundMethodArrayRootSlot($block, $calleeSlot);
        if (null === $arraySlot) {
            return null;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ADD_ARRAY_ELEMENT !== $op->type || $op->arg1 !== $arraySlot) {
                continue;
            }
            if (null === $op->arg3 || !isset($block->constants[$op->arg3])) {
                continue;
            }
            if (1 !== $block->constants[$op->arg3]->toInt()) {
                continue;
            }
            $methodSlot = $op->arg2;
            if (isset($block->constants[$methodSlot])) {
                return strtolower($block->constants[$methodSlot]->toString());
            }
            foreach ($block->opCodes as $prior) {
                if (
                    OpCode::TYPE_ASSIGN !== $prior->type
                    || $prior->arg2 !== $methodSlot
                    || !isset($block->constants[$prior->arg3])
                ) {
                    continue;
                }

                return strtolower($block->constants[$prior->arg3]->toString());
            }
        }

        return null;
    }

    public static function resolveBoundMethodReceiverOperand(Block $block, int $calleeSlot): ?Operand
    {
        $arraySlot = self::resolveBoundMethodArrayRootSlot($block, $calleeSlot);
        if (null === $arraySlot) {
            return null;
        }
        $receiver = self::receiverOperandForArraySlot($block, $arraySlot);
        if (null === $receiver) {
            return null;
        }

        return self::resolveObjectOperandRoot($block, $receiver);
    }

    /**
     * Enum case FCC receivers (`E::A->f(...)`) use TYPE_CLASS_CONST_FETCH; infer enum FQCN (#6845).
     */
    public static function resolveBoundMethodReceiverClassName(Block $block, int $calleeSlot): ?string
    {
        $arraySlot = self::resolveBoundMethodArrayRootSlot($block, $calleeSlot);
        if (null === $arraySlot) {
            return null;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY !== $op->type || $op->arg1 !== $arraySlot || null === $op->arg2) {
                continue;
            }

            return self::classNameFromReceiverSlot($block, (int) $op->arg2);
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $name = self::resolveBoundMethodReceiverClassName($parent, $calleeSlot);
            if (null !== $name) {
                return $name;
            }
        }

        return null;
    }

    private static function classNameFromReceiverSlot(Block $block, int $slot, array &$visited = []): ?string
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_CLASS_CONST_FETCH === $op->type && $op->arg1 === $slot) {
                $classOp = $block->getOperand($op->arg2);
                if ($classOp instanceof Operand\Literal) {
                    return (string) $classOp->value;
                }
            }
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($op->arg2 !== $slot && $op->arg1 !== $slot) {
                continue;
            }
            $resolved = self::classNameFromReceiverSlot($block, (int) $op->arg3, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = self::classNameFromReceiverSlot($parent, $slot, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * php-cfg often uses temporaries for FCC receivers; follow assigns back to $obj (#4040).
     */
    private static function resolveObjectOperandRoot(
        Block $block,
        Operand $op,
        array &$visited = []
    ): ?Operand {
        if ($op instanceof Operand\Variable) {
            return $op;
        }
        if (!$op instanceof Operand\Temporary) {
            return $op;
        }
        if (null !== $op->type && Type::TYPE_OBJECT === $op->type->type) {
            return $op;
        }
        $slot = $block->slotForOperand($op);
        if (null === $slot) {
            return $op;
        }
        $blockId = spl_object_id($block);
        if (isset($visited[$blockId][$slot])) {
            return $op;
        }
        $visited[$blockId][$slot] = true;
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_NEW === $prior->type && $prior->arg1 === $slot) {
                return $block->getOperand($prior->arg1);
            }
        }
        foreach ($block->opCodes as $prior) {
            if (OpCode::TYPE_ASSIGN !== $prior->type) {
                continue;
            }
            if ($prior->arg2 !== $slot && $prior->arg1 !== $slot) {
                continue;
            }

            return self::resolveObjectOperandRoot(
                $block,
                $block->getOperand((int) $prior->arg3),
                $visited
            );
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = self::resolveObjectOperandRoot($parent, $op, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return $op;
    }

    private static function receiverOperandForArraySlot(
        Block $block,
        int $arraySlot,
        array &$visited = []
    ): ?Operand {
        $id = spl_object_id($block);
        if (isset($visited[$id])) {
            return null;
        }
        $visited[$id] = true;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && $op->arg1 === $arraySlot && null !== $op->arg2) {
                return $block->getOperand($op->arg2);
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = self::receiverOperandForArraySlot($parent, $arraySlot, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Invokable-object FCC `(new C)(...)` — callee slot holds object, not `[obj, method]` (#9605).
     *
     * Must not match null/scalar/array variables: those are runtime non-callables and must
     * Error with Zend FCC wording (#28937), not lower as `__invoke` on a fake "object".
     */
    public static function resolveInvokableObjectReceiverOperand(Block $block, int $slot): ?Operand
    {
        if (null !== self::resolveBoundMethodArrayRootSlot($block, $slot)) {
            return null;
        }
        // Require TYPE_NEW (or object-typed operand) — bare Variable/$tmp is not enough (#28937).
        if (null === self::classNameFromObjectSlot($block, $slot)) {
            $candidate = self::operandForInvokableObjectSlot($block, $slot);
            $isObjectTyped = null !== $candidate->type && Type::TYPE_OBJECT === $candidate->type->type;
            if (!$isObjectTyped && !self::slotHasNewOpcode($block, $slot)) {
                return null;
            }
        }

        return self::resolveObjectOperandRoot($block, self::operandForInvokableObjectSlot($block, $slot));
    }

    /** True when $slot (or an assign-chain source) is defined by TYPE_NEW (#9605 / #28937). */
    private static function slotHasNewOpcode(Block $block, int $slot, array &$visited = []): bool
    {
        $visitKey = spl_object_id($block).':'.$slot;
        if (isset($visited[$visitKey])) {
            return false;
        }
        $visited[$visitKey] = true;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type && $op->arg1 === $slot) {
                return true;
            }
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($op->arg2 !== $slot && $op->arg1 !== $slot) {
                continue;
            }
            if (self::slotHasNewOpcode($block, (int) $op->arg3, $visited)) {
                return true;
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            if (self::slotHasNewOpcode($parent, $slot, $visited)) {
                return true;
            }
        }

        return false;
    }

    public static function resolveInvokableObjectClassName(Block $block, int $slot): ?string
    {
        if (null !== self::resolveBoundMethodArrayRootSlot($block, $slot)) {
            return null;
        }

        return self::classNameFromObjectSlot($block, $slot);
    }

    public static function resolveBoundMethodArrayRootSlot(
        Block $block,
        int $slot,
        array &$visited = []
    ): ?int {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_INIT_ARRAY === $op->type && $op->arg1 === $slot) {
                return $slot;
            }
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($op->arg2 !== $slot && $op->arg1 !== $slot) {
                continue;
            }
            $resolved = self::resolveBoundMethodArrayRootSlot($block, (int) $op->arg3, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = self::resolveBoundMethodArrayRootSlot($parent, $slot, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    private static function operandForInvokableObjectSlot(
        Block $block,
        int $slot,
        array &$visited = []
    ): Operand {
        if (isset($visited[$slot])) {
            return $block->getOperand($slot);
        }
        $visited[$slot] = true;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW === $op->type && $op->arg1 === $slot) {
                return $block->getOperand($op->arg1);
            }
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($op->arg2 !== $slot && $op->arg1 !== $slot) {
                continue;
            }

            return self::operandForInvokableObjectSlot($block, (int) $op->arg3, $visited);
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = self::operandForInvokableObjectSlot($parent, $slot, $visited);
            if ($resolved instanceof Operand\Temporary || $resolved instanceof Operand\Variable) {
                return $resolved;
            }
        }

        return $block->getOperand($slot);
    }

    private static function classNameFromObjectSlot(
        Block $block,
        int $slot,
        array &$visited = []
    ): ?string {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_NEW !== $op->type || $op->arg1 !== $slot) {
                continue;
            }
            $classOp = $block->getOperand($op->arg2);
            if ($classOp instanceof Operand\Literal) {
                return (string) $classOp->value;
            }
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($op->arg2 !== $slot && $op->arg1 !== $slot) {
                continue;
            }
            $resolved = self::classNameFromObjectSlot($block, (int) $op->arg3, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $resolved = self::classNameFromObjectSlot($parent, $slot, $visited);
            if (null !== $resolved) {
                return $resolved;
            }
        }

        return null;
    }

    /**
     * Compile-time `['Class','method']()` array callable — class and method operand slots (#32299).
     *
     * Bound `[object, method]` stays on {@see resolveBoundMethodReceiverOperand}; this path is
     * only the two-string static form. php-src: Zend/zend_execute.c ZEND_INIT_DYNAMIC_CALL.
     *
     * @return array{0:int,1:int}|null
     */
    public static function resolveStaticArrayCallableSlots(Block $block, int $calleeSlot): ?array
    {
        $arraySlot = self::resolveBoundMethodArrayRootSlot($block, $calleeSlot);
        if (null === $arraySlot) {
            return null;
        }
        $classValueSlot = self::arrayElementValueSlot($block, $arraySlot, 0);
        $methodValueSlot = self::arrayElementValueSlot($block, $arraySlot, 1);
        if (null === $classValueSlot || null === $methodValueSlot) {
            return null;
        }
        $classConstSlot = self::constantStringSlot($block, $classValueSlot);
        $methodConstSlot = self::constantStringSlot($block, $methodValueSlot);
        if (null === $classConstSlot || null === $methodConstSlot) {
            return null;
        }

        return [$classConstSlot, $methodConstSlot];
    }

    private static function arrayElementValueSlot(
        Block $block,
        int $arraySlot,
        int $key,
        array &$visited = []
    ): ?int {
        $id = spl_object_id($block).':'.$arraySlot.':'.$key;
        if (isset($visited[$id])) {
            return null;
        }
        $visited[$id] = true;
        $packedIndex = 0;
        foreach ($block->opCodes as $op) {
            if (
                (OpCode::TYPE_INIT_ARRAY !== $op->type && OpCode::TYPE_ADD_ARRAY_ELEMENT !== $op->type)
                || $op->arg1 !== $arraySlot
                || null === $op->arg2
            ) {
                continue;
            }
            $explicit = self::opcodeExplicitIntKey($block, $op);
            $thisKey = $explicit ?? $packedIndex;
            if (null === $explicit) {
                ++$packedIndex;
            } else {
                $packedIndex = $explicit + 1;
            }
            if ($thisKey === $key) {
                return (int) $op->arg2;
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $found = self::arrayElementValueSlot($parent, $arraySlot, $key, $visited);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }

    private static function opcodeExplicitIntKey(Block $block, OpCode $op): ?int
    {
        if (null === $op->arg3 || !isset($block->constants[$op->arg3])) {
            return null;
        }
        $c = $block->constants[$op->arg3];
        if (Variable::TYPE_INTEGER !== $c->type) {
            return null;
        }

        return $c->toInt();
    }

    private static function constantStringSlot(Block $block, int $slot, array &$visited = []): ?int
    {
        $id = spl_object_id($block).':'.$slot;
        if (isset($visited[$id])) {
            return null;
        }
        $visited[$id] = true;
        if (isset($block->constants[$slot]) && Variable::TYPE_STRING === $block->constants[$slot]->type) {
            return $slot;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if ($op->arg2 !== $slot && $op->arg1 !== $slot) {
                continue;
            }
            $src = (int) $op->arg3;
            if (isset($block->constants[$src]) && Variable::TYPE_STRING === $block->constants[$src]->type) {
                return $src;
            }
            $nested = self::constantStringSlot($block, $src, $visited);
            if (null !== $nested) {
                return $nested;
            }
        }
        foreach ($block->parents as $parent) {
            if (!$parent instanceof Block) {
                continue;
            }
            $found = self::constantStringSlot($parent, $slot, $visited);
            if (null !== $found) {
                return $found;
            }
        }

        return null;
    }
}
