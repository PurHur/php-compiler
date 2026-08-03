<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_count_values() PHP helper (#12331).
 *
 * Thin AOT NestedJIT of this helper aborts (#27213); JIT/AOT use
 * {@see \PHPCompiler\JIT\ArrayCountValuesLlvm} via ArrayCountValuesRuntime.
 * Kept as executable PHP SSOT for unit tests (peer ArrayFlipJitHelper / #26970).
 *
 * SSOT: {@see VmArray::countValues()}
 * php-src: ext/standard/array.c — php_array_count_values()
 */
final class ArrayCountValuesJitHelper
{
    private const SKIP_WARNING =
        'array_count_values(): Can only count string and integer values, entry skipped';

    public static function countValues(HashTable $ht): HashTable
    {
        $out = new HashTable();
        foreach ($ht->iterateKeyed(true) as [, $value]) {
            $v = $value->resolveIndirect();
            if (!self::isCountValuesScalar($v)) {
                compiler_language_warning(self::SKIP_WARNING);
                continue;
            }
            if (Variable::TYPE_STRING === $v->type) {
                $key = $v->toString();
                $existing = $out->find($key);
                if (null === $existing) {
                    $count = new Variable();
                    $count->int(1);
                    $out->add($key, $count);
                } else {
                    $resolved = $existing->resolveIndirect();
                    $resolved->int($resolved->toInt() + 1);
                }
                continue;
            }
            if (Variable::TYPE_INTEGER === $v->type) {
                $idx = $v->toInt();
                $existing = $out->findIndex($idx);
                if (null === $existing) {
                    $count = new Variable();
                    $count->int(1);
                    $out->addIndex($idx, $count);
                } else {
                    $resolved = $existing->resolveIndirect();
                    $resolved->int($resolved->toInt() + 1);
                }
            }
        }

        return $out;
    }

    private static function isCountValuesScalar(Variable $var): bool
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return false;
        }

        return Variable::TYPE_STRING === $var->type || Variable::TYPE_INTEGER === $var->type;
    }
}
