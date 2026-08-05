<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ReflectionTypeSupport;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/**
 * array_unique() VM SSOT (#12341, php-in-PHP).
 *
 * AOT/JIT use {@see \PHPCompiler\JIT\ArrayUniqueLlvm} via ArrayUniqueRuntime (#27066).
 * php-src: ext/standard/array.c — php_array_unique()
 */
final class ArrayUniqueJitHelper
{
    public static function unique(HashTable $ht, int $flags): HashTable
    {
        $flags = self::normalizeFlags($flags);
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        $out = new HashTable();
        $seen = new HashTable();
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            self::assertUniqueElement($value, $flags);
            if (self::isSeen($value, $seen, $sortType)) {
                continue;
            }
            self::markSeen($value, $seen, $sortType);
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
     * SORT_STRING: objects/enums without __toString must throw (#4698, #5531).
     * NestedJIT-safe: never call VM::castObjectToString() (#27066).
     */
    private static function assertUniqueElement(Variable $value, int $flags): void
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
        self::assertObjectStringableForUnique($value->toObject());
    }

    private static function assertObjectStringableForUnique(ObjectEntry $object): void
    {
        if (ResourceSupport::isResourceObject($object)) {
            return;
        }
        if (null !== ReflectionTypeSupport::tryObjectTypeString($object)) {
            return;
        }
        if (isset($object->class->methods['__tostring'])) {
            return;
        }
        throw new \Error(
            'Object of class '.$object->class->name.' could not be converted to string'
        );
    }

    private static function isSeen(Variable $value, HashTable $seen, int $sortType): bool
    {
        if (StdlibConstants::SORT_STRING === $sortType) {
            return null !== $seen->find($value->resolveIndirect()->toString());
        }
        if (StdlibConstants::SORT_NUMERIC === $sortType) {
            return null !== $seen->find(self::numericSeenKey($value));
        }
        foreach ($seen->iterateKeyed(true) as [$_key, $seenValue]) {
            if ($value->equals($seenValue)) {
                return true;
            }
        }

        return false;
    }

    private static function markSeen(Variable $value, HashTable $seen, int $sortType): void
    {
        $marker = new Variable();
        $marker->int(1);
        if (StdlibConstants::SORT_STRING === $sortType) {
            $seen->update($value->resolveIndirect()->toString(), $marker);

            return;
        }
        if (StdlibConstants::SORT_NUMERIC === $sortType) {
            $seen->update(self::numericSeenKey($value), $marker);

            return;
        }
        $copy = new Variable();
        $copy->copyFrom($value);
        $seen->append($copy);
    }

    private static function numericSeenKey(Variable $value): string
    {
        $n = self::numericUniqueScalar($value);
        if (\is_float($n)) {
            return 'f:'.(string) $n;
        }

        return 'i:'.(string) $n;
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

            return (float) $s;
        }

        return 0;
    }
}
