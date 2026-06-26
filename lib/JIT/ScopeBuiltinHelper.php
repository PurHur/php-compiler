<?php

declare(strict_types=1);

/**
 * Orchestration for extract() / compact() / get_defined_vars() JIT lowering (#10184).
 *
 * LLVM emission: {@see ScopeBuiltinEmitHelper}
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmScope}
 */

namespace PHPCompiler\JIT;

use PHPCompiler\Block as CompilerBlock;
use PHPCompiler\ext\standard\VmScope;
use PHPCompiler\Web\Superglobals;
use PHPLLVM\Value;

final class ScopeBuiltinHelper
{
    /**
     * @return array<string, Variable>
     */
    public static function namedVariablesInScope(Context $context): array
    {
        $map = [];
        foreach ($context->scope->variables as $op) {
            $name = OperandName::resolve($op);
            if (null === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            $var = $context->scope->variables[$op];
            if (Variable::TYPE_HASHTABLE === $var->type || 0 !== ($var->type & Variable::IS_NATIVE_ARRAY)) {
                continue;
            }
            $map[$name] = $var;
        }

        return $map;
    }

    public static function findVariableByName(Context $context, string $name): ?Variable
    {
        return self::namedVariablesInScope($context)[$name] ?? null;
    }

    public static function findCompactVariableByName(Context $context, string $name): ?Variable
    {
        $local = self::findVariableByName($context, $name);
        if (null !== $local) {
            return $local;
        }
        if (!Superglobals::isSuperglobalName($name)) {
            return null;
        }

        return SuperglobalInit::load($context, $name);
    }

    /**
     * @return Value int64 import count
     */
    public static function extract(Context $context, Variable $array, ?Variable $flagsArg = null, ?Variable $prefixArg = null): Value
    {
        if (ArrayBuiltinHelper::isNativeArray($array->type)) {
            throw new \LogicException('extract() first argument must be an array in this compiler build');
        }
        if (Variable::TYPE_HASHTABLE !== $array->type) {
            throw new \LogicException('extract() first argument must be an array in this compiler build');
        }

        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $flags = self::resolveFlags($context, $flagsArg);
        $named = self::namedVariablesInScope($context);
        if ([] === $named) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        $i64 = $context->getTypeFromString('int64');
        $countSlot = $context->builder->alloca($i64, 1, 'extract_count');
        $context->builder->store($i64->constInt(0, false), $countSlot);

        ScopeBuiltinEmitHelper::walkStringKeyNodes($context, $ht, $named, $flags, $countSlot, $prefixArg);

        return $context->builder->load($countSlot);
    }

    /** parse_str() one-arg: import parsed keys into named locals (#3708). */
    public static function importHashtableIntoScope(Context $context, Value $ht): void
    {
        $named = self::namedVariablesInScope($context);
        if ([] === $named) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $flags = $i64->constInt(0, false);
        ScopeBuiltinEmitHelper::walkStringKeyNodes($context, $ht, $named, $flags, null);
    }

    public static function compact(Context $context, Variable ...$nameArgs): Value
    {
        return ScopeBuiltinEmitHelper::buildCompact($context, ...$nameArgs);
    }

    /**
     * @return array<string, Variable>
     */
    public static function namedVariablesForDefinedVars(Context $context): array
    {
        $map = [];
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        if (!$block instanceof CompilerBlock) {
            return $map;
        }
        foreach ($block->eachNamedScopeSlot() as [$name, $_slot]) {
            if ('this' === $name || Superglobals::isSuperglobalName($name)) {
                continue;
            }
            $var = VarFetchHelper::bindingByName($context, $block, $name);
            if (null === $var) {
                continue;
            }
            if (0 !== ($var->type & Variable::IS_NATIVE_ARRAY)) {
                continue;
            }
            $map[$name] = $var;
        }

        return $map;
    }

    public static function getDefinedVars(Context $context): Value
    {
        return ScopeBuiltinEmitHelper::getDefinedVars($context);
    }

    public static function getDeclaredVariables(Context $context): Value
    {
        return ScopeBuiltinEmitHelper::getDeclaredVariables($context);
    }

    private static function resolveFlags(Context $context, ?Variable $flagsArg): Value
    {
        $i64 = $context->getTypeFromString('int64');
        if (null === $flagsArg) {
            return $i64->constInt(VmScope::EXTR_OVERWRITE, false);
        }

        return JitLongArg::lower($context, $flagsArg, 'extract() flags');
    }
}
