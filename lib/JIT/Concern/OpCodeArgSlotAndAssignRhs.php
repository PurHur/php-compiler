<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;

/**
 * Opcode arg-slot typing and assign/binary RHS resolution (#36387).
 *
 * Extracted from {@see CoerceReturnPropertyDeclaringAndByRef}:
 * {@code operandAt} through {@code assignRhsSlot}, plus
 * {@code assignOperandsUsedByLiteralInclude}.
 * Move-only Concern hollow toward split-TU / size-budget Done-when.
 *
 * php-src: Zend/zend_compile.c (ASSIGN / binary operand slots),
 * Zend/zend_execute.c (CV formal RHS) — no new C ABI and no opcode/IR shape change.
 */
trait OpCodeArgSlotAndAssignRhs
{
    private function operandAt(Block $block, ?int $slot, string $context): Operand
    {
        if (null === $slot) {
            throw new \LogicException('Missing operand slot for '.$context);
        }

        return $block->getOperand($slot);
    }

    /**
     * php-types fact for a value-scope arg index (see Block::opCodeValueScopeArgs) (#36249).
     */
    private function opCodeValueArgType(OpCode $op, int $valueArgIndex): ?\PHPTypes\Type
    {
        return $op->argTypes[$valueArgIndex] ?? null;
    }

    /**
     * Primary result type for an opcode; falls back to the dest operand when unstamped (#36249).
     */
    private function opCodeResultType(Block $block, OpCode $op): ?\PHPTypes\Type
    {
        if ($op->resultType instanceof \PHPTypes\Type) {
            return $op->resultType;
        }
        if (null === $op->arg1) {
            return null;
        }
        $dest = $block->getOperand((int) $op->arg1);

        return ($dest?->type instanceof \PHPTypes\Type) ? $dest->type : null;
    }

    /**
     * Type for a specific opcode arg slot (arg1/arg2/arg3), via stamped value-scope index (#36249).
     */
    private function opCodeArgSlotType(Block $block, OpCode $op, int $argSlot): ?\PHPTypes\Type
    {
        $scopeArgs = $block->opCodeValueScopeArgs($op);
        foreach ($scopeArgs as $index => $slot) {
            if (null !== $slot && (int) $slot === $argSlot) {
                $typed = $this->opCodeValueArgType($op, (int) $index);
                if ($typed instanceof \PHPTypes\Type) {
                    return $typed;
                }
                $operand = $block->getOperand($argSlot);

                return ($operand?->type instanceof \PHPTypes\Type) ? $operand->type : null;
            }
        }

        return null;
    }

    /** Match/phi merge may leave TYPE_ASSIGN arg3 null; rhs lives in arg1 (#13092). */
    /** AssignOp peephole may leave arg2 null; lvalue lives in arg1 (#13062, #6438). */
    /** Resolve `$v` RHS from a callee formal CV before Temporary null-box fallback (#32654). */
    private function tryResolveFormalParamVariableForRhs(Block $block, Operand $rhsOperand): ?Variable
    {
        if (null === $block->func) {
            return null;
        }
        $rhsName = JIT\OperandName::resolve($rhsOperand);
        if (null !== $rhsName && '' !== $rhsName) {
            $resolvedRhs = $this->context->resolveRefAliasName($rhsName);
            if (isset($this->context->namedVariableBindings[$resolvedRhs])) {
                return $this->context->namedVariableBindings[$resolvedRhs];
            }
        }
        $rhsSlotNum = $block->slotForOperand($rhsOperand);
        if (null === $rhsSlotNum) {
            return null;
        }
        foreach ($block->func->params as $param) {
            if ($block->slotForOperand($param->result) !== $rhsSlotNum) {
                continue;
            }
            $pname = JIT\OperandName::resolve($param->result);
            if (null !== $pname && '' !== $pname) {
                $resolved = $this->context->resolveRefAliasName($pname);
                if (isset($this->context->namedVariableBindings[$resolved])) {
                    return $this->context->namedVariableBindings[$resolved];
                }
            }
            if ($this->context->hasVariableOp($param->result)) {
                return $this->context->getVariableFromOp($param->result);
            }

            return null;
        }

        return null;
    }

    private function resolveAssignRhsFromFormalParam(
        Block $block,
        Operand $rhsOperand,
        Variable $value
    ): Variable {
        $formal = $this->tryResolveFormalParamVariableForRhs($block, $rhsOperand);

        return null !== $formal ? $formal : $value;
    }

    private function binaryOpLeftSlot(OpCode $op): int
    {
        if (null !== $op->arg2) {
            return (int) $op->arg2;
        }
        if (null === $op->arg1) {
            throw new \LogicException('Missing operand slot for '.opcode_type_name($op->type).' left');
        }

        return (int) $op->arg1;
    }

    private function binaryOpLeftOperand(Block $block, OpCode $op): Operand
    {
        return $this->operandAt($block, $this->binaryOpLeftSlot($op), opcode_type_name($op->type).' left');
    }

    /** Match/ternary merge may omit arg3; phi RHS lives in arg2 (#9159, #13092). */
    private function assignRhsSlot(OpCode $op): int
    {
        if (null !== $op->arg3) {
            return (int) $op->arg3;
        }
        if (null === $op->arg2) {
            throw new \LogicException('Missing operand slot for TYPE_ASSIGN value');
        }

        return (int) $op->arg2;
    }

    private function assignOperandsUsedByLiteralInclude(Block $block, OpCode $op): bool
    {
        if ([] === $block->literalIncludePaths) {
            return false;
        }
        foreach ($block->literalIncludePaths as $path) {
            if (!is_file($path)) {
                continue;
            }
            $code = file_get_contents($path);
            if (false === $code || '' === $code) {
                continue;
            }
            foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                $name = JIT\OperandName::resolve($block->getOperand($slotIdx));
                if (null === $name || '' === $name) {
                    continue;
                }
                if (preg_match('/\\$'.preg_quote($name, '/').'\\b/', $code)) {
                    return true;
                }
            }
        }

        return false;
    }
}
