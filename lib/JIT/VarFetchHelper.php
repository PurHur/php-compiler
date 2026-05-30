<?php

declare(strict_types=1);

/**
 * LLVM lowering for dynamic variable fetch (`$$name`, issue #1226).
 *
 * Phase 1: compile-time string names (literal assignment to the name operand).
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\Web\Superglobals;
use PHPCfg\Operand;

final class VarFetchHelper
{
    public static function resolveTarget(Context $context, Block $block, Variable $nameVar, bool $forWrite = false): Variable
    {
        if (null === $nameVar->compileTimeString) {
            throw new \LogicException(
                'Variable-variable JIT lowering requires a compile-time string name (issue #1226)'
            );
        }
        $name = $context->resolveRefAliasName($nameVar->compileTimeString);
        $target = self::resolveBinding($context, $block, $name);
        if (null === $target) {
            if ($forWrite) {
                return self::ensureBinding($context, $block, $name);
            }
            throw new \LogicException('Undefined variable $'.$nameVar->compileTimeString);
        }

        return $target;
    }

    public static function ensureBinding(Context $context, Block $block, string $name): Variable
    {
        $existing = self::resolveBinding($context, $block, $name);
        if (null !== $existing) {
            return $existing;
        }
        $slot = $block->slotIndexForVariableName($name);
        if (null !== $slot) {
            foreach ($block->scopedOperands() as $op) {
                if ($block->slotForOperand($op) !== $slot) {
                    continue;
                }
                if (!$context->hasVariableOp($op)) {
                    throw new \LogicException('Variable-variable write requires a bound slot for $'.$name);
                }

                return $context->getVariableFromOp($op);
            }
        }

        throw new \LogicException('Variable-variable write requires a compile-time local for $'.$name);
    }

    public static function bindingByName(Context $context, Block $block, string $name): ?Variable
    {
        return self::resolveBinding($context, $block, $context->resolveRefAliasName($name));
    }

    private static function resolveBinding(Context $context, Block $block, string $name): ?Variable
    {
        if (isset($context->namedVariableBindings[$name])) {
            return $context->namedVariableBindings[$name];
        }
        foreach ($context->scope->variables as $op) {
            if ($name === OperandName::resolve($op)) {
                return $context->scope->variables[$op];
            }
        }
        foreach ($context->scopeStack as $scope) {
            foreach ($scope->variables as $op) {
                if ($name === OperandName::resolve($op)) {
                    return $scope->variables[$op];
                }
            }
        }
        $slot = $block->slotIndexForVariableName($name);
        if (null !== $slot) {
            $operands = [];
            foreach ($block->scopedOperands() as $op) {
                if ($block->slotForOperand($op) === $slot && $context->hasVariableOp($op)) {
                    $operands[] = $op;
                }
            }
            $best = null;
            $bestRank = -1;
            foreach ($operands as $op) {
                $rank = self::operandBindingRank($op);
                if ($rank > $bestRank) {
                    $bestRank = $rank;
                    $best = $op;
                }
            }
            if (null !== $best) {
                return $context->getVariableFromOp($best);
            }
        }
        $found = ScopeBuiltinHelper::findVariableByName($context, $name);
        if (null !== $found) {
            return $found;
        }
        if (Superglobals::isSuperglobalName($name)) {
            return SuperglobalInit::load($context, $name);
        }

        return null;
    }

    private static function operandBindingRank(Operand $op): int
    {
        $resolved = OperandName::resolve($op);
        if ($op instanceof \PHPCfg\Operand\Temporary && null !== $resolved && '' !== $resolved) {
            return 3;
        }
        if ($op instanceof \PHPCfg\Operand\Variable) {
            return 2;
        }

        return 1;
    }
}
