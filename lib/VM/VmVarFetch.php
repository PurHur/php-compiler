<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\JIT\ScopeBuiltinHelper;
use PHPCompiler\JIT\SuperglobalInit;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\Web\Superglobals;
use PHPCfg\Operand;

/**
 * SSOT for dynamic variable fetch (`$$name`, issue #1226, #10289).
 *
 * php-src: Zend/zend_compile.c — variable-variable compile
 * php-src: Zend/zend_execute.c — ZEND_FETCH_R/W, $GLOBALS / symbol table
 */
final class VmVarFetch
{
    public static function isSuperglobalName(string $name): bool
    {
        return Superglobals::isSuperglobalName($name);
    }

    public static function operandBindingRank(Operand $op): int
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

    public static function resolveCompileTimeTarget(
        Context $context,
        Block $block,
        JitVariable $nameVar,
        bool $forWrite = false
    ): JitVariable {
        if (null === $nameVar->compileTimeString) {
            throw new \LogicException(
                'Variable-variable JIT lowering requires a compile-time string name (issue #1226)'
            );
        }
        $name = $context->resolveRefAliasName($nameVar->compileTimeString);
        $target = self::resolveCompileTimeBinding($context, $block, $name);
        if (null === $target) {
            if ($forWrite) {
                return self::ensureCompileTimeBinding($context, $block, $name);
            }
            throw new \LogicException('Undefined variable $'.$nameVar->compileTimeString);
        }

        return $target;
    }

    public static function ensureCompileTimeBinding(Context $context, Block $block, string $name): JitVariable
    {
        $existing = self::resolveCompileTimeBinding($context, $block, $name);
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

    public static function compileTimeBindingByName(Context $context, Block $block, string $name): ?JitVariable
    {
        return self::resolveCompileTimeBinding($context, $block, $context->resolveRefAliasName($name));
    }

    private static function resolveCompileTimeBinding(Context $context, Block $block, string $name): ?JitVariable
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
        if (self::isSuperglobalName($name)) {
            return SuperglobalInit::load($context, $name);
        }

        return null;
    }
}
