<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_count_values() for compiled JIT/AOT modules (#12331, php-in-PHP).
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
