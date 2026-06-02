<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\OpCode;
use PHPTypes\Type;

/**
 * Compile-time folding for bound-method first-class callables `[object, method]` (#3566, #4040).
 */
final class BoundMethodCallableHelper
{
    public static function isBoundMethodArrayCallee(Operand $op, Variable $var): bool
    {
        if (null !== $op->type && Type::TYPE_ARRAY === $op->type->type) {
            return true;
        }
        if (0 !== ($var->type & Variable::TYPE_HASHTABLE)) {
            return true;
        }
        if (Variable::TYPE_VALUE === $var->type && null !== $var->valueBoxHashtable) {
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
     * Follow TYPE_ASSIGN chains from a call-site slot to the INIT_ARRAY root (#4040).
     */
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
}
