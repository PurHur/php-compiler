<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\Frame;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\ext\filter\JitFilter;
use PHPCompiler\ext\filter\VmFilter;
use PHPCompiler\ext\filter\filter_var_array as FilterVarArrayInternal;
use PHPLLVM\Value;

/**
 * JIT/AOT link for filter_var_array() (#3294, #21937, #34574).
 *
 * Thin AOT NestedJIT of FilterBatchJitHelper fatals on `new HashTable()` /
 * iterateKeyed and mis-marshals Variable returns as empty VmIni (#21981 /
 * peer #26970 / #34572). Const-fold via {@see VmFilter} when possible; else
 * call-site LLVM {@see FilterVarArrayLlvm}.
 *
 * php-src: ext/filter/filter.c — php_filter_var_array
 */
final class FilterVarArrayRuntime
{
    public static function filter(
        Context $context,
        JITVariable $data,
        JITVariable $definition,
        int $addEmpty
    ): Value {
        $folded = self::tryFoldConst($context, $data, $definition, $addEmpty);
        if (null !== $folded) {
            return $folded;
        }

        return FilterVarArrayLlvm::filter($context, $data, $definition, $addEmpty);
    }

    public static function ensureLinked(Context $context): void
    {
        // Call-site LLVM — no NestedJIT helper unit (#34574).
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    private static function tryFoldConst(
        Context $context,
        JITVariable $data,
        JITVariable $definition,
        int $addEmpty
    ): ?Value {
        if (!\is_array($data->compileTimeAssoc)) {
            return null;
        }
        $dataHt = self::phpAssocToHashTable($data->compileTimeAssoc);
        if (FilterVarArrayLlvm::isArrayDefinitionPublic($definition)) {
            if (!\is_array($definition->compileTimeAssoc)) {
                return null;
            }
            $def = self::phpAssocToHashTable($definition->compileTimeAssoc);
        } else {
            $filterId = self::tryConstFilterId($definition);
            if (null === $filterId) {
                return null;
            }
            $def = $filterId;
        }
        $frame = new Frame(new FilterVarArrayInternal(), null, null);
        $result = VmFilter::filterVarArray($dataHt, $def, $addEmpty, $frame);
        if (null === $result) {
            return JitFilter::boxedFalse($context);
        }
        $htVar = HashTableHelper::variableFromVmHashTable($context, $result);

        return HashTableHelper::loadHashtablePointer($context, $htVar);
    }

    private static function tryConstFilterId(JITVariable $filterArg): ?int
    {
        if (null !== $filterArg->compileTimeLong
            && (JITVariable::TYPE_NATIVE_LONG === $filterArg->type
                || JITVariable::TYPE_VALUE === $filterArg->type
                || JITVariable::TYPE_NATIVE_BOOL === $filterArg->type)) {
            return (int) $filterArg->compileTimeLong;
        }

        return null;
    }

    /** @param array<string|int, mixed> $php */
    private static function phpAssocToHashTable(array $php): HashTable
    {
        $ht = new HashTable();
        foreach ($php as $key => $val) {
            $cell = self::phpScalarToVariable($val);
            if (\is_int($key) || (\is_string($key) && ctype_digit($key) && (string) (int) $key === $key)) {
                $ht->addIndex((int) $key, $cell);
            } else {
                $ht->add((string) $key, $cell);
            }
        }

        return $ht;
    }

    private static function phpScalarToVariable(mixed $val): VmVariable
    {
        $out = new VmVariable();
        if (null === $val) {
            $out->null();
        } elseif (\is_bool($val)) {
            $out->bool($val);
        } elseif (\is_int($val)) {
            $out->int($val);
        } elseif (\is_float($val)) {
            $out->float($val);
        } elseif (\is_string($val)) {
            $out->string($val);
        } elseif (\is_array($val)) {
            $out->array(self::phpAssocToHashTable($val));
        } else {
            $out->null();
        }

        return $out;
    }
}
