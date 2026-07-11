<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_flip() for compiled JIT/AOT modules (#12329, php-in-PHP).
 *
 * SSOT: {@see VmArray::flip()}
 * php-src: ext/standard/array.c — php_array_flip()
 */
final class ArrayFlipJitHelper
{
    private const SKIP_WARNING =
        'array_flip(): Can only flip string and integer values, entry skipped';

    public static function flip(HashTable $ht): HashTable
    {
        $out = new HashTable();
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyVar = $key->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $keyVar->type && Variable::TYPE_STRING !== $keyVar->type) {
                throw new \TypeError('Illegal offset type');
            }
            $val = $value->resolveIndirect();
            if (!self::isFlipScalar($val)) {
                compiler_language_warning(self::SKIP_WARNING);
                continue;
            }
            $stored = new Variable();
            $stored->copyFrom($key);
            if (Variable::TYPE_INTEGER === $val->type) {
                $out->updateIndex($val->toInt(), $stored);
            } else {
                $out->update($val->toString(), $stored);
            }
        }

        return $out;
    }

    private static function isFlipScalar(Variable $var): bool
    {
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            return false;
        }

        return Variable::TYPE_STRING === $var->type || Variable::TYPE_INTEGER === $var->type;
    }
}
