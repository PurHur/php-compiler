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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
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
        // Match VmScope::resolveCompactVariable — auto-globals only on {main} (#25898).
        $block = $context->jitCurrentBlock ?? $context->jitEnclosingBlock;
        if (!$block instanceof CompilerBlock || !$block->isMainScript()) {
            return null;
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
        $ht = ArrayBuiltinHelper::loadHashTable($context, $array);
        $flags = self::resolveFlags($context, $flagsArg);
        $named = self::namedVariablesInScope($context);
        if ([] === $named) {
            return $context->getTypeFromString('int64')->constInt(0, false);
        }

        $i64 = $context->getTypeFromString('int64');
        $countSlot = $context->builder->alloca($i64, 1, 'extract_count');
        $context->builder->store($i64->constInt(0, false), $countSlot);

        $prefixStr = $context->builder->load($context->constantStringFromString(''));
        if (null !== $prefixArg) {
            $prefixStr = JitStringBuiltinArg::lowerRequiredString($context, $prefixArg, 'extract', 2, 'prefix');
        }

        ScopeBuiltinEmitHelper::walkStringKeyNodes(
            $context,
            $ht,
            $named,
            $flags,
            $countSlot,
            $prefixStr,
            null === $flagsArg
        );

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
        $emptyPrefix = $context->builder->load($context->constantStringFromString(''));
        ScopeBuiltinEmitHelper::walkStringKeyNodes($context, $ht, $named, $flags, null, $emptyPrefix, true);
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
            if ('this' === $name) {
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

        foreach (self::namedVariablesInScope($context) as $name => $var) {
            if (isset($map[$name]) || 0 !== ($var->type & Variable::IS_NATIVE_ARRAY)) {
                continue;
            }
            $map[$name] = $var;
        }

        if ($block->isMainScript()) {
            foreach (VmScope::FILE_SCOPE_DEFINED_VAR_AUTO_NAMES as $name) {
                if (isset($map[$name])) {
                    continue;
                }
                if (Superglobals::isSuperglobalName($name)) {
                    try {
                        $map[$name] = SuperglobalInit::load($context, $name);
                    } catch (\LogicException) {
                        continue;
                    }
                    continue;
                }
                $global = $context->ensureScriptGlobal($name);
                if (0 === ($global->type & Variable::IS_NATIVE_ARRAY)) {
                    $map[$name] = $global;
                }
            }
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
