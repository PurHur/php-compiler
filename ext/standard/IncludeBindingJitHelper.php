<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPTypes\Type;
use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\OperandName;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\Web\Superglobals;

/**
 * Compile-time include local-binding analysis for JIT/AOT (#10063, php-in-PHP).
 *
 * php-src: Zend/zend_execute.c — include inherits caller symbol table by name
 */
final class IncludeBindingJitHelper
{
    /**
     * Zend include/require: callee reads caller locals by variable name (issue #471).
     *
     * @return \SplObjectStorage<Operand, Variable>
     */
    public static function collectCalleeLocalBindings(
        Context $context,
        Block $callerBlock,
        Block $includedBlock
    ): \SplObjectStorage {
        $bindings = new \SplObjectStorage();
        $calleeOps = [];
        foreach ($includedBlock->scopedOperands() as $operand) {
            $calleeOps[spl_object_id($operand)] = $operand;
        }
        foreach ($includedBlock->argOperands() as $operand) {
            $calleeOps[spl_object_id($operand)] = $operand;
        }
        foreach ($calleeOps as $operand) {
            $name = OperandName::resolve($operand);
            if (null === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            if (!self::callerDeclaresLocalName($callerBlock, $name)) {
                continue;
            }
            $callerVar = self::callerVariableForName($context, $callerBlock, $name);
            if (null !== $callerVar) {
                $bindings[$operand] = $callerVar;
            }
        }

        return $bindings;
    }

    public static function callerVariableForName(
        Context $context,
        Block $callerBlock,
        string $name
    ): ?Variable {
        $scoped = $context->variableForScopedName($name);
        if (
            null !== $scoped
            && (Variable::TYPE_VALUE === $scoped->type || Variable::TYPE_STRING === $scoped->type)
        ) {
            return $scoped;
        }
        $callerOp = self::callerOperandByName($callerBlock, $name);
        if (null !== $callerOp && $context->hasVariableOpInScopes($callerOp)) {
            $var = $context->getVariableFromOpInScopes($callerOp);
            if (Variable::TYPE_VALUE === $var->type || Variable::TYPE_STRING === $var->type) {
                return $var;
            }
        }
        $lastAssign = self::lastAssignVariableForName($context, $callerBlock, $name);
        if (null !== $lastAssign) {
            return $lastAssign;
        }

        return self::variableFromCallerAssignConstant($context, $callerBlock, $name);
    }

    public static function stableCallerValueSlot(
        Context $context,
        Block $callerBlock,
        string $name
    ): ?Variable {
        $resolved = $context->resolveRefAliasName($name);
        foreach ($callerBlock->orig->hoistedOperands as $operand) {
            $opName = OperandName::resolve($operand);
            if (null === $opName || $resolved !== $context->resolveRefAliasName($opName)) {
                continue;
            }
            if (!$context->hasVariableOpInScopes($operand)) {
                continue;
            }
            $var = $context->getVariableFromOpInScopes($operand);
            if (
                Variable::TYPE_VALUE === $var->type
                && Variable::KIND_VARIABLE === $var->kind
            ) {
                return $var;
            }
        }
        foreach ($callerBlock->scopedOperands() as $operand) {
            $opName = OperandName::resolve($operand);
            if (null === $opName || $resolved !== $context->resolveRefAliasName($opName)) {
                continue;
            }
            if (!$context->hasVariableOpInScopes($operand)) {
                continue;
            }
            $var = $context->getVariableFromOpInScopes($operand);
            if (
                Variable::TYPE_VALUE === $var->type
                && Variable::KIND_VARIABLE === $var->kind
            ) {
                return $var;
            }
        }

        return null;
    }

    /**
     * Prefer stable caller slots over ephemeral rvalues at include_entry (#846).
     */
    public static function resolveIncludeCallerVar(
        Context $context,
        ?string $name,
        Variable $callerVar,
        Block $callerBlock
    ): Variable {
        if (null === $name || '' === $name) {
            return $callerVar;
        }
        $candidates = [$callerVar];
        $slotBlock = $context->listUnpackAssignRootBlock ?? $callerBlock;
        $stable = self::stableCallerValueSlot($context, $slotBlock, $name);
        if (null !== $stable) {
            $candidates[] = $stable;
        }
        foreach (self::variablesForScopedNameInCallerScopes($context, $name) as $scopedVar) {
            $candidates[] = $scopedVar;
        }
        $resolved = $context->resolveRefAliasName($name);
        if (isset($context->namedVariableBindings[$resolved])) {
            $candidates[] = $context->namedVariableBindings[$resolved];
        }
        if (isset($context->listUnpackAssignSlots[$resolved])) {
            $candidates[] = $context->listUnpackAssignSlots[$resolved];
        }
        $scoped = $context->variableForScopedName($name);
        if (null !== $scoped) {
            $candidates[] = $scoped;
        }
        $lastAssign = self::lastAssignVariableForName($context, $callerBlock, $name);
        if (null !== $lastAssign) {
            $candidates[] = $lastAssign;
        }
        if (null !== $context->listUnpackAssignRootBlock) {
            $rootAssign = self::lastAssignVariableForName(
                $context,
                $context->listUnpackAssignRootBlock,
                $name
            );
            if (null !== $rootAssign) {
                $candidates[] = $rootAssign;
            }
        }
        $best = null;
        $bestScore = -1;
        foreach ($candidates as $candidate) {
            $score = self::includeCallerBindingScore($candidate);
            if ($score > $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        return null !== $best && $bestScore > 0 ? $best : $callerVar;
    }

    public static function includeCallerBindingScore(Variable $candidate): int
    {
        if (Variable::TYPE_VALUE === $candidate->type) {
            if (Variable::KIND_VARIABLE === $candidate->kind) {
                return 4;
            }
            if (Variable::KIND_VALUE === $candidate->kind) {
                return 1;
            }
        }
        if (
            Variable::TYPE_STRING === $candidate->type
            && Variable::KIND_VARIABLE === $candidate->kind
        ) {
            return 2;
        }

        return 0;
    }

    /**
     * @return list<Variable>
     */
    public static function variablesForScopedNameInCallerScopes(Context $context, string $name): array
    {
        $vars = [];
        foreach ($context->scope->variables as $op) {
            if (OperandName::resolve($op) === $name) {
                $vars[] = $context->scope->variables[$op];
            }
        }
        for ($i = \count($context->scopeStack) - 1; $i >= 0; --$i) {
            foreach ($context->scopeStack[$i]->variables as $op) {
                if (OperandName::resolve($op) === $name) {
                    $vars[] = $context->scopeStack[$i]->variables[$op];
                }
            }
        }

        return $vars;
    }

    public static function lastAssignVariableForName(
        Context $context,
        Block $block,
        string $name
    ): ?Variable {
        $visited = [];

        return self::lastAssignVariableForNameInBlock($context, $block, $name, $visited);
    }

    /**
     * @param array<int, true> $visited
     */
    public static function lastAssignVariableForNameInBlock(
        Context $context,
        Block $block,
        string $name,
        array &$visited
    ): ?Variable {
        $blockId = \spl_object_id($block);
        if (isset($visited[$blockId])) {
            return null;
        }
        $visited[$blockId] = true;
        $lastAssign = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type) {
                if (null !== $op->arg2) {
                    $alias = $block->getOperand($op->arg2);
                    $aliasName = OperandName::resolve($alias);
                    if (
                        null !== $aliasName
                        && $name === $aliasName
                        && $op->arg1 !== $op->arg2
                    ) {
                        $storage = $block->getOperand($op->arg1);
                        if ($context->hasVariableOpInScopes($storage)) {
                            $storageVar = $context->getVariableFromOpInScopes($storage);
                            if (
                                Variable::KIND_VARIABLE === $storageVar->kind
                                && (Variable::TYPE_VALUE === $storageVar->type || Variable::TYPE_STRING === $storageVar->type)
                            ) {
                                $lastAssign = $storageVar;
                            }
                        }
                    }
                }
                foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                    $dest = $block->getOperand($slotIdx);
                    if (OperandName::resolve($dest) !== $name) {
                        continue;
                    }
                    if ($context->hasVariableOpInScopes($dest)) {
                        $var = $context->getVariableFromOpInScopes($dest);
                        if (
                            Variable::KIND_VARIABLE === $var->kind
                            && (Variable::TYPE_VALUE === $var->type || Variable::TYPE_STRING === $var->type)
                        ) {
                            $lastAssign = $var;
                        }
                    }
                }
            }
            if (null !== $op->block1) {
                $nested = self::lastAssignVariableForNameInBlock($context, $op->block1, $name, $visited);
                if (null !== $nested) {
                    $lastAssign = $nested;
                }
            }
            if (null !== $op->block2) {
                $nested = self::lastAssignVariableForNameInBlock($context, $op->block2, $name, $visited);
                if (null !== $nested) {
                    $lastAssign = $nested;
                }
            }
        }

        return $lastAssign;
    }

    public static function variableFromCallerAssignConstant(
        Context $context,
        Block $callerBlock,
        string $name
    ): ?Variable {
        foreach ($callerBlock->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            $matches = false;
            foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                $dest = $callerBlock->getOperand($slotIdx);
                if (OperandName::resolve($dest) === $name) {
                    $matches = true;
                    break;
                }
            }
            if (!$matches || !isset($callerBlock->constants[$op->arg3])) {
                continue;
            }
            $constant = $callerBlock->constants[$op->arg3];
            if (!$constant instanceof VmVariable || VmVariable::TYPE_STRING !== $constant->type) {
                continue;
            }
            $lit = new Literal($constant->toString());
            $lit->type = Type::string();

            return Variable::fromLiteral($context, $lit);
        }

        return null;
    }

    public static function callerOperandByName(Block $block, string $name): ?Operand
    {
        foreach ($block->scopedOperands() as $operand) {
            if (OperandName::resolve($operand) === $name) {
                return $operand;
            }
        }
        foreach ($block->orig->hoistedOperands as $operand) {
            if (OperandName::resolve($operand) === $name) {
                return $operand;
            }
        }

        return null;
    }

    public static function callerDeclaresLocalName(Block $callerBlock, string $name): bool
    {
        return null !== self::callerOperandByName($callerBlock, $name);
    }

    /**
     * Prefer JIT scope live values at the include site (renderHello $_REQUEST, #784).
     *
     * @param \SplObjectStorage<Operand, Variable> $localBindings
     */
    public static function syncLocalBindingsFromScope(
        Context $context,
        \SplObjectStorage $localBindings,
        Block $callerBlock
    ): void {
        foreach ($localBindings as $operand) {
            $name = OperandName::resolve($operand);
            if (null === $name) {
                continue;
            }
            $live = $context->variableForScopedName($name);
            if (
                null !== $live
                && (Variable::TYPE_VALUE === $live->type || Variable::TYPE_STRING === $live->type)
            ) {
                $localBindings[$operand] = self::resolveIncludeCallerVar(
                    $context,
                    $name,
                    $live,
                    $callerBlock
                );
            }
        }
    }

    public static function hasMultipleAssignsInCaller(Block $callerBlock, string $name): bool
    {
        $count = 0;
        $visited = [];
        self::countAssignsToName($callerBlock, $name, $count, $visited);

        return $count > 1;
    }

    /**
     * @param array<int, true> $visited
     */
    public static function countAssignsToName(Block $block, string $name, int &$count, array &$visited): void
    {
        $blockId = \spl_object_id($block);
        if (isset($visited[$blockId])) {
            return;
        }
        $visited[$blockId] = true;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN === $op->type) {
                foreach ([$op->arg1, $op->arg2] as $slotIdx) {
                    $dest = $block->getOperand($slotIdx);
                    if (OperandName::resolve($dest) === $name) {
                        ++$count;
                        if ($count > 1) {
                            return;
                        }
                    }
                }
            }
            if (null !== $op->block1) {
                self::countAssignsToName($op->block1, $name, $count, $visited);
                if ($count > 1) {
                    return;
                }
            }
            if (null !== $op->block2) {
                self::countAssignsToName($op->block2, $name, $count, $visited);
                if ($count > 1) {
                    return;
                }
            }
        }
    }
}
