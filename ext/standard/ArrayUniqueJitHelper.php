<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * array_unique() for compiled JIT/AOT modules (#12341, php-in-PHP).
 *
 * SSOT shared with {@see array_unique} VM execute()
 * php-src: ext/standard/array.c — php_array_unique()
 */
final class ArrayUniqueJitHelper
{
    public static function unique(HashTable $ht, int $flags, ?Frame $frame = null): HashTable
    {
        $flags = self::normalizeFlags($flags);
        $out = new HashTable();
        $seen = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            self::assertUniqueElement($value, $flags, $frame);
            if (self::isDuplicate($value, $seen, $flags)) {
                continue;
            }
            $seenCopy = new Variable();
            $seenCopy->copyFrom($value);
            $seen[] = $seenCopy;
            $stored = new Variable();
            $stored->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $stored);
            } else {
                $out->add($key->toString(), $stored);
            }
        }

        return $out;
    }

    /**
     * SORT_STRING: objects and enum cases without __toString must throw (ext/standard/array.c, #4698, #5531).
     */
    private static function assertUniqueElement(Variable $value, int $flags, ?Frame $frame): void
    {
        if (StdlibConstants::SORT_STRING !== ($flags & ~StdlibConstants::SORT_FLAG_CASE)) {
            return;
        }
        $value = $value->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            throw new \Error(
                'Object of class '.EnumCaseSupport::typeNameForVariable($value).' could not be converted to string'
            );
        }
        if (Variable::TYPE_OBJECT !== $value->type) {
            return;
        }
        if (null !== $frame && null !== $frame->vmContext && null !== $frame->vmContext->runtime->vm) {
            $frame->vmContext->runtime->vm->castObjectToString($value->toObject());

            return;
        }
        $value->toString();
    }

    public static function normalizeFlagsForCall(int $flags): int
    {
        return self::normalizeFlags($flags);
    }

    private static function normalizeFlags(int $flags): int
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (
            StdlibConstants::SORT_REGULAR !== $sortType
            && StdlibConstants::SORT_STRING !== $sortType
            && StdlibConstants::SORT_NUMERIC !== $sortType
        ) {
            throw new \LogicException(
                'array_unique() flags are not supported in this compiler build'
            );
        }

        return $flags;
    }

    /** @param list<Variable> $seen */
    private static function isDuplicate(Variable $value, array $seen, int $flags): bool
    {
        foreach ($seen as $seenValue) {
            if (self::valuesMatchForUnique($value, $seenValue, $flags)) {
                return true;
            }
        }

        return false;
    }

    private static function valuesMatchForUnique(Variable $a, Variable $b, int $flags): bool
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (StdlibConstants::SORT_STRING === $sortType) {
            return $a->resolveIndirect()->toString() === $b->resolveIndirect()->toString();
        }
        if (StdlibConstants::SORT_NUMERIC === $sortType) {
            return 0 === self::compareNumericOperandsForUnique($a, $b);
        }

        return $a->equals($b);
    }

    private static function compareNumericOperandsForUnique(Variable $a, Variable $b): int
    {
        $av = self::numericUniqueScalar($a);
        $bv = self::numericUniqueScalar($b);
        if (\is_float($av) || \is_float($bv)) {
            return (float) $av <=> (float) $bv;
        }

        return (int) $av <=> (int) $bv;
    }

    private static function numericUniqueScalar(Variable $value): int|float
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_INTEGER === $value->type) {
            return $value->toInt();
        }
        if (Variable::TYPE_FLOAT === $value->type) {
            return $value->toFloat();
        }
        if (Variable::TYPE_BOOLEAN === $value->type) {
            return $value->toBool() ? 1 : 0;
        }
        if (Variable::TYPE_NULL === $value->type) {
            return 0;
        }
        if (Variable::TYPE_STRING === $value->type) {
            $s = $value->toString();
            if (is_numeric($s)) {
                if (((string) (int) $s) === $s
                    && !str_contains($s, '.')
                    && !str_contains(strtolower($s), 'e')) {
                    return (int) $s;
                }

                return (float) $s;
            }
            if (!preg_match('/^\s*[+-]?(?:(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?)/', $s, $m)) {
                return 0;
            }
            $numPart = ltrim($m[0], " \t\n\r\0\x0B");
            if (((string) (int) $numPart) === $numPart
                && !str_contains($numPart, '.')
                && !str_contains(strtolower($numPart), 'e')) {
                return (int) $numPart;
            }

            return (float) $numPart;
        }

        return 0;
    }
}
