<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;

/** VM array helpers (no PHP internal wrappers in compiled paths). */
final class VmArray
{
    /** count() mode — php-src ext/standard/basic_functions.c (#3511). */
    public const COUNT_NORMAL = 0;

    public const COUNT_RECURSIVE = 1;

    /**
     * php-src array.c php_add_var / php_multiply_var skip enum case elements (#5578).
     */
    public static function shouldSkipNumericArrayFoldElement(Variable $value): bool
    {
        return EnumCaseSupport::isEnumCaseVariable($value);
    }

    /**
     * php-src ext/standard/array.c zval_get_long semantics for array_sum()/array_product() (#4278).
     *
     * Non-numeric strings and unconvertible objects contribute 0. Enum cases are skipped (null).
     *
     * @return array{0: int|float, 1: bool}|null [numeric value, is-float]
     */
    public static function coerceArrayFoldNumericElement(Variable $value): ?array
    {
        $value = $value->resolveIndirect();
        if (self::shouldSkipNumericArrayFoldElement($value)) {
            return null;
        }

        return match ($value->type) {
            Variable::TYPE_NULL => [0, false],
            Variable::TYPE_INTEGER => [$value->toInt(), false],
            Variable::TYPE_FLOAT => [$value->toFloat(), true],
            Variable::TYPE_BOOLEAN => [$value->toInt(), false],
            Variable::TYPE_STRING => self::coerceArrayFoldNumericString($value->toString()),
            Variable::TYPE_OBJECT => self::coerceArrayFoldNumericObject($value),
            Variable::TYPE_RESOURCE => [ResourceSupport::resolveHandle($value) ?? 0, false],
            default => [0, false],
        };
    }

    /**
     * @return array{0: int|float, 1: bool}
     */
    private static function coerceArrayFoldNumericString(string $s): array
    {
        if (!is_numeric($s)) {
            return [0, false];
        }
        if (((string) (int) $s) === $s
            && !str_contains($s, '.')
            && !str_contains(strtolower($s), 'e')) {
            return [(int) $s, false];
        }

        return [(float) $s, true];
    }

    /**
     * @return array{0: int|float, 1: bool}
     */
    private static function coerceArrayFoldNumericObject(Variable $value): array
    {
        $object = $value->toObject();
        if (ResourceSupport::isResourceObject($object)) {
            $handle = ResourceSupport::resolveHandle($value);

            return [null !== $handle ? $handle : 0, false];
        }

        return [0, false];
    }

    /**
     * array_intersect/diff* reject enum case keys/values before string hash (#5927, php-src array.c).
     */
    public static function rejectEnumCaseSetOpOperands(HashTable ...$tables): void
    {
        foreach ($tables as $table) {
            foreach ($table->iterateKeyed(true) as [$key, $value]) {
                $value = $value->resolveIndirect();
                if (EnumCaseSupport::isEnumCaseVariable($value)) {
                    $enumClass = EnumCaseSupport::enumClassForCaseVariable($value);
                    throw new \Error(
                        'Object of class '.($enumClass->name ?? 'enum').' could not be converted to string'
                    );
                }
                $key = $key->resolveIndirect();
                if (EnumCaseSupport::isEnumCaseVariable($key)) {
                    $enumClass = EnumCaseSupport::enumClassForCaseVariable($key);
                    throw new \Error(
                        'Object of class '.($enumClass->name ?? 'enum').' could not be converted to string'
                    );
                }
            }
        }
    }

    /**
     * array_combine/array_fill_keys keys must be string or int — enum cases Error (ext/standard/array.c).
     */
    public static function rejectEnumCaseKeyVariable(Variable $key): void
    {
        $key = $key->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($key)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($key);
            throw new \Error(
                'Object of class '.($enumClass->name ?? 'enum').' could not be converted to string'
            );
        }
    }

    /**
     * array_combine() key slot — Zend convert_to_key rules (ext/standard/array.c, #4161).
     */
    public static function storeCombineKey(HashTable $ht, Variable $key, Variable $stored, ?Frame $frame = null): void
    {
        $key = $key->resolveIndirect();
        self::rejectEnumCaseKeyVariable($key);
        $resourceKey = VmVarFormat::tryFormatPrintR($key);
        if (null !== $resourceKey) {
            $ht->update($resourceKey, $stored);

            return;
        }
        if (Variable::TYPE_ARRAY === $key->type) {
            self::warnArrayToStringKeyCoercion($frame);
            $ht->update('Array', $stored);

            return;
        }
        if (Variable::TYPE_INTEGER === $key->type) {
            $ht->updateIndex($key->toInt(), $stored);

            return;
        }
        if (Variable::TYPE_FLOAT === $key->type) {
            $floatKey = $key->toFloat();
            $intKey = (int) $floatKey;
            if ($floatKey === (float) $intKey) {
                $ht->updateIndex($intKey, $stored);
            } else {
                $ht->update($key->toString(), $stored);
            }

            return;
        }
        if (Variable::TYPE_STRING === $key->type) {
            $ht->update($key->toString(), $stored);

            return;
        }
        if (Variable::TYPE_BOOLEAN === $key->type) {
            $ht->updateIndex($key->toBool() ? 1 : 0, $stored);

            return;
        }
        if (Variable::TYPE_NULL === $key->type) {
            $ht->update('', $stored);

            return;
        }
        if (Variable::TYPE_OBJECT === $key->type) {
            throw new \Error(
                'Object of class '.$key->toObject()->class->name.' could not be converted to string'
            );
        }

        throw new \Error(
            'Object of class '.self::valueTypeLabel($key).' could not be converted to string'
        );
    }

    /**
     * natsort/natcasesort natural compare requires string operands — Zend rejects enum cases (#5607).
     */
    public static function rejectEnumCaseNaturalSortValue(Variable $value): void
    {
        $value = $value->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            throw new \Error(
                'Object of class '.EnumCaseSupport::typeNameForVariable($value).' could not be converted to string'
            );
        }
    }

    public static function isList(HashTable $ht): bool
    {
        $n = $ht->getNumElements();
        if (0 === $n) {
            return true;
        }
        $expected = 0;
        foreach ($ht->iterateKeyed() as $pair) {
            $keyVar = $pair[0];
            if (Variable::TYPE_INTEGER !== $keyVar->type) {
                return false;
            }
            if ($keyVar->toInt() !== $expected) {
                return false;
            }
            ++$expected;
        }

        return $expected === $n;
    }

    /** array_is_assoc() — non-empty and not a list (issue #7016, ext/standard/array.c). */
    public static function isAssoc(HashTable $ht): bool
    {
        if (0 === $ht->getNumElements()) {
            return false;
        }

        return !self::isList($ht);
    }

    /**
     * All keys are integer or canonical numeric strings (php-src array_merge numeric branch; #4231).
     */
    public static function hasOnlyNumericKeys(HashTable $ht): bool
    {
        foreach ($ht->iterateKeyed() as $pair) {
            $keyVar = $pair[0]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $keyVar->type) {
                continue;
            }
            if (Variable::TYPE_STRING === $keyVar->type) {
                if (!self::isCanonicalNonNegativeIntStringKey($keyVar->toString())) {
                    return false;
                }
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Packed list or numeric-string keys 0..n-1 (Zend zend_hash numeric-key rules; #3607).
     */
    public static function isReindexableList(HashTable $ht): bool
    {
        if (self::isList($ht)) {
            return true;
        }
        $n = $ht->getNumElements();
        if (0 === $n) {
            return true;
        }
        $expected = 0;
        foreach ($ht->iterateKeyed() as $pair) {
            $keyVar = $pair[0]->resolveIndirect();
            if (Variable::TYPE_INTEGER === $keyVar->type) {
                $idx = $keyVar->toInt();
            } elseif (Variable::TYPE_STRING === $keyVar->type) {
                $s = $keyVar->toString();
                if (!self::isCanonicalNonNegativeIntStringKey($s)) {
                    return false;
                }
                $idx = (int) $s;
            } else {
                return false;
            }
            if ($idx !== $expected) {
                return false;
            }
            ++$expected;
        }

        return $expected === $n;
    }

    private static function isCanonicalNonNegativeIntStringKey(string $s): bool
    {
        return '' !== $s && (string) (int) $s === $s && (int) $s >= 0;
    }

    /**
     * array_merge() subset: packed 0..n-1 lists append; string-key maps overwrite later keys (#2287).
     *
     * @param HashTable ...$others
     */
    public static function merge(HashTable $first, HashTable ...$others): HashTable
    {
        if ([] === $others) {
            return self::mergeSingleArgumentCopy($first);
        }

        foreach ([$first, ...$others] as $ht) {
            if (!self::hasOnlyNumericKeys($ht)) {
                $out = self::mergeSingleArgumentCopy($first);
                foreach ($others as $other) {
                    self::mergeArrayInto($out, $other, true);
                }

                return $out;
            }
        }

        if (self::isList($first) && self::allLists($others)) {
            return $first->mergeCopy(...$others);
        }

        $out = new HashTable();
        foreach ([$first, ...$others] as $ht) {
            foreach ($ht->iterateKeyed(true) as [, $value]) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $out->append($copy);
            }
        }

        return $out;
    }

    /**
     * Merge a subsequent array_merge() operand — string keys overwrite; integer keys append (#11155).
     */
    public static function mergeArrayInto(HashTable $dest, HashTable $other, bool $overwriteStringKeys): void
    {
        foreach ($other->iterateKeyed(true) as [$key, $value]) {
            $key = $key->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $dest->append($copy);
                continue;
            }
            if (Variable::TYPE_STRING === $key->type) {
                $s = $key->toString();
                if (self::isCanonicalNonNegativeIntStringKey($s)) {
                    $dest->append($copy);
                    continue;
                }
                $existing = $dest->find($s);
                if (null !== $existing) {
                    if ($overwriteStringKeys) {
                        $existing->copyFrom($copy);
                    }
                    continue;
                }
                $dest->add($s, $copy);
                continue;
            }
            $sk = $key->toString();
            $existing = $dest->find($sk);
            if (null !== $existing) {
                if ($overwriteStringKeys) {
                    $existing->copyFrom($copy);
                }
                continue;
            }
            $dest->add($sk, $copy);
        }
    }

    /**
     * array_merge() with one source array — reindex integer keys, preserve string keys (php-src array.c).
     */
    public static function mergeSingleArgumentCopy(HashTable $first): HashTable
    {
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->append($copy);
            } elseif (Variable::TYPE_STRING === $key->type) {
                $s = $key->toString();
                if (self::isCanonicalNonNegativeIntStringKey($s)) {
                    $out->append($copy);
                } else {
                    $out->add($s, $copy);
                }
            } else {
                $out->add($key->toString(), $copy);
            }
        }

        return $out;
    }

    /** @param list<HashTable> $others */
    private static function allLists(array $others): bool
    {
        foreach ($others as $ht) {
            if (!self::isList($ht)) {
                return false;
            }
        }

        return true;
    }

    public static function keyFirst(HashTable $ht): ?Variable
    {
        $ht->iterReset();
        if (!$ht->iterValid()) {
            return null;
        }

        return $ht->iterCurrentKey();
    }

    public static function keyLast(HashTable $ht): ?Variable
    {
        $ht->iterReset();
        $last = null;
        while ($ht->iterValid()) {
            $last = $ht->iterCurrentKey();
        }

        return $last;
    }

    /**
     * array_first() — first element value (php-src array.c, #3491, #7293).
     *
     * Returns null when the hash table has no elements (empty or all-unset).
     */
    public static function valueFirst(HashTable $ht): ?Variable
    {
        $ht->iterReset();
        if (!$ht->iterValid()) {
            return null;
        }

        return $ht->iterCurrentValue();
    }

    /**
     * array_last() — last element value (php-src array.c, #3491, #7293).
     *
     * Returns null when the hash table has no elements (empty or all-unset).
     */
    public static function valueLast(HashTable $ht): ?Variable
    {
        $ht->iterReset();
        $last = null;
        while ($ht->iterValid()) {
            $last = $ht->iterCurrentValue();
        }

        return $last;
    }

    /**
     * @throws \TypeError when {@param $value} is not an array
     */
    public static function requireArray(Variable $value, string $fn): HashTable
    {
        return self::requireArrayParam($value, $fn, 1, 'array');
    }

    /**
     * @throws \TypeError when {@param $value} is not an array
     */
    public static function requireArrayParam(
        Variable $value,
        string $fn,
        int $argNum,
        string $paramName,
        string $expectedType = 'array'
    ): HashTable {
        $v = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $v->type) {
            throw new \TypeError(
                \sprintf(
                    '%s(): Argument #%d ($%s) must be of type %s, %s given',
                    $fn,
                    $argNum,
                    $paramName,
                    $expectedType,
                    self::valueTypeLabel($v)
                )
            );
        }

        return $v->toArray();
    }

    /**
     * Variadic array builtins whose Zend messages omit ($param) — e.g. array_merge().
     *
     * @throws \TypeError when {@param $value} is not an array
     */
    public static function requireArrayArgNum(Variable $value, string $fn, int $argNum): HashTable
    {
        $v = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $v->type) {
            throw new \TypeError(
                \sprintf(
                    '%s(): Argument #%d must be of type array, %s given',
                    $fn,
                    $argNum,
                    self::valueTypeLabel($v)
                )
            );
        }

        return $v->toArray();
    }

    /**
     * array_pad() — pad packed list {@param $array} to abs({@param $length}) with {@param $value}.
     */
    public static function pad(HashTable $array, int $length, Variable $value): HashTable
    {
        return $array->padCopy($length, $value);
    }

    /**
     * array_fill_keys() — keys from values of {@param $keys}, uniform {@param $value}.
     */
    public static function fillKeys(HashTable $keys, Variable $value, ?Frame $frame = null): HashTable
    {
        $dest = new HashTable();
        foreach ($keys->iterateKeyed(true) as [, $keyValue]) {
            $stored = new Variable();
            $stored->copyFrom($value);
            self::storeCombineKey($dest, $keyValue, $stored, $frame);
        }

        return $dest;
    }

    /** Zend _array_to_string / array key coercion — warn and use literal "Array" (#10848, ext/standard/array.c). */
    private static function warnArrayToStringKeyCoercion(?Frame $frame): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->languageWarning(
            'Array to string conversion',
            null,
            0,
            $frame->vmContext,
            $frame
        );
    }

    /** ksort() — return array sorted by key ascending (php-src: no list-shaped skip; #10836). */
    public static function ksortCopy(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $pairs = self::copyKeyedPairs($ht);
        VmInternalCompare::sortKeyedPairsByKey($pairs, $flags);
        return self::hashTableFromSortedPairs($pairs);
    }

    /** krsort() — return array sorted by key descending (php-src: no list-shaped skip; #10836). */
    public static function krsortCopy(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $pairs = self::copyKeyedPairs($ht);
        VmInternalCompare::sortKeyedPairsByKeyDesc($pairs, $flags);
        return self::hashTableFromSortedPairs($pairs);
    }

    /** asort() — return array sorted by value ascending; packed lists are unchanged (handled in-place). */
    public static function asortCopy(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): HashTable
    {
        if ($ht->getNumElements() < 2 || self::isList($ht)) {
            return $ht;
        }
        $pairs = self::copyKeyedPairs($ht);
        self::sortKeyedPairsByValue($pairs, $flags, 'asort()', false);
        return self::hashTableFromSortedPairs($pairs);
    }

    /** natsort() — return array sorted by value using natural order; preserves keys (#9600). */
    public static function natsortCopy(HashTable $ht): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $pairs = self::copyKeyedPairs($ht);
        $values = array_map(static fn (array $pair): Variable => $pair[1], $pairs);
        if (VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_STRING)) {
            VmInternalCompare::sortKeyedPairsByValue(
                $pairs,
                VmInternalCompare::resolveStringCallback('strnatcmp')
            );
        } elseif (VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_INTEGER)) {
            VmInternalCompare::sortKeyedPairsByValueInt($pairs);
        } else {
            VmInternalCompare::sortKeyedPairsByValueWithFlags($pairs, StdlibConstants::SORT_REGULAR);
        }

        return self::hashTableFromSortedPairs($pairs);
    }

    /** natcasesort() — return array sorted by value using natural case-insensitive order (#2372, #9600). */
    public static function natcasesortCopy(HashTable $ht): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $pairs = self::copyKeyedPairs($ht);
        $values = array_map(static fn (array $pair): Variable => $pair[1], $pairs);
        if (VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_STRING)) {
            VmInternalCompare::sortKeyedPairsByValue(
                $pairs,
                VmInternalCompare::resolveStringCallback('strnatcasecmp')
            );
        } elseif (VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_INTEGER)) {
            VmInternalCompare::sortKeyedPairsByValueInt($pairs);
        } else {
            VmInternalCompare::sortKeyedPairsByValueWithFlags($pairs, StdlibConstants::SORT_REGULAR);
        }

        return self::hashTableFromSortedPairs($pairs);
    }

    /** arsort() — return array sorted by value descending; packed lists are unchanged (handled in-place). */
    public static function arsortCopy(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): HashTable
    {
        if ($ht->getNumElements() < 2 || self::isList($ht)) {
            return $ht;
        }
        $pairs = self::copyKeyedPairs($ht);
        self::sortKeyedPairsByValue($pairs, $flags, 'arsort()', true);

        return self::hashTableFromSortedPairs($pairs);
    }

    /**
     * shuffle() — Fisher–Yates on values (CSPRNG via {@see VmString::randomBytes()}).
     *
     * Packed lists shuffle in place; associative arrays reindex to 0..n-1 (php-src array.c).
     */
    public static function shufflePacked(HashTable $ht): void
    {
        $n = $ht->getNumElements();
        if ($n < 2) {
            return;
        }
        $values = [];
        foreach ($ht->iterate(true) as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $values[] = $copy;
        }
        self::fisherYatesShuffleVariables($values);
        if (self::isList($ht)) {
            $ht->replacePackedValues($values);
        } else {
            $ht->assignPackedList($values);
        }
    }

    /**
     * array_rand() — pick {@param $num} unique keys (CSPRNG; issue #2321, #4460).
     */
    public static function arrayRandPacked(HashTable $ht, int $num): Variable
    {
        $n = $ht->getNumElements();
        if (0 === $n) {
            throw new \ValueError('array_rand(): Argument #1 ($array) cannot be empty');
        }
        if ($num < 1 || $num > $n) {
            throw new \ValueError(
                'array_rand(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)'
            );
        }
        $keys = [];
        foreach ($ht->iterateKeyed() as $pair) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($pair[0]);
            $keys[] = $keyCopy;
        }
        for ($i = 0; $i < $num; ++$i) {
            $j = $i + (self::randomIndexBelow($n - $i));
            $tmp = $keys[$i];
            $keys[$i] = $keys[$j];
            $keys[$j] = $tmp;
        }
        $picked = \array_slice($keys, 0, $num);
        $result = new Variable();
        if (1 === $num) {
            $result->copyFrom($picked[0]);

            return $result;
        }
        $arr = new HashTable();
        foreach ($picked as $pos => $key) {
            $v = new Variable();
            $v->copyFrom($key);
            $arr->addIndex($pos, $v);
        }
        $result->array($arr);

        return $result;
    }

    /**
     * @param list<Variable> $values
     */
    private static function fisherYatesShuffleVariables(array &$values): void
    {
        $n = \count($values);
        for ($i = $n - 1; $i > 0; --$i) {
            $j = self::randomIndexBelow($i + 1);
            $tmp = $values[$i];
            $values[$i] = $values[$j];
            $values[$j] = $tmp;
        }
    }

    private static function randomIndexBelow(int $upper): int
    {
        if ($upper <= 1) {
            return 0;
        }
        $rand = VmString::randomBytes(8);
        $pick = 0;
        for ($b = 0; $b < 8; ++$b) {
            $pick = ($pick << 8) | \ord($rand[$b]);
        }
        $j = $pick % $upper;
        if ($j < 0) {
            $j += $upper;
        }

        return $j;
    }

    private const COUNT_RECURSIVE_WARNING = 'count(): Recursion detected';

    /**
     * count($array, COUNT_RECURSIVE) — php-src ext/standard/array.c (#3511, #10083).
     *
     * Top-level element count plus recursive counts of nested arrays (PHP 8.2+).
     * Cyclic arrays emit E_WARNING and return a bounded count (php_count_recursive).
     */
    public static function countRecursive(HashTable $ht, ?Frame $frame = null, ?\SplObjectStorage $visited = null): int
    {
        if (null === $visited) {
            $visited = new \SplObjectStorage();
        }
        if ($visited->contains($ht)) {
            self::countRecursiveWarning($frame);

            return 0;
        }
        $visited->attach($ht);
        try {
            $count = $ht->getNumElements();
            foreach ($ht->iterate(true) as $value) {
                if (Variable::TYPE_ARRAY === $value->type) {
                    $count += self::countRecursive($value->toArray(), $frame, $visited);
                }
            }

            return $count;
        } finally {
            $visited->detach($ht);
        }
    }

    private static function countRecursiveWarning(?Frame $frame): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            self::COUNT_RECURSIVE_WARNING,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    private const COUNT_VALUES_SKIP_WARNING =
        'array_count_values(): Can only count string and integer values, entry skipped';

    private const FLIP_SKIP_WARNING =
        'array_flip(): Can only flip string and integer values, entry skipped';

    /**
     * array_flip() — swap keys and values for int/string pairs (#4295, ext/standard/array.c).
     */
    public static function flip(HashTable $ht, ?Frame $frame = null): HashTable
    {
        $out = new HashTable();
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyVar = $key->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $keyVar->type && Variable::TYPE_STRING !== $keyVar->type) {
                throw new \TypeError('Illegal offset type');
            }
            $val = $value->resolveIndirect();
            if (!self::isFlipScalar($val)) {
                self::flipSkipWarning($frame);
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

    private static function flipSkipWarning(?Frame $frame): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            self::FLIP_SKIP_WARNING,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    /**
     * array_count_values() — count occurrences of string or integer values (#2356, #4267).
     */
    public static function countValues(HashTable $ht, ?Frame $frame = null): HashTable
    {
        $out = new HashTable();
        foreach ($ht->iterateKeyed(true) as [, $value]) {
            $v = $value->resolveIndirect();
            if (!self::isCountValuesScalar($v)) {
                self::countValuesSkipWarning($frame);
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
                continue;
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

    private static function countValuesSkipWarning(?Frame $frame): void
    {
        if (null === $frame?->vmContext) {
            return;
        }
        $frame->vmContext->errors->triggerError(
            self::COUNT_VALUES_SKIP_WARNING,
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $frame->vmContext,
            $frame
        );
    }

    /** array_change_key_case() — copy with ASCII string keys lowercased or uppercased. */
    public static function changeKeyCase(HashTable $ht, int $case): HashTable
    {
        $out = new HashTable();
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $resolvedKey = $key->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($resolvedKey)) {
                throw new \TypeError('Illegal offset type');
            }
            if (Variable::TYPE_INTEGER !== $resolvedKey->type && Variable::TYPE_STRING !== $resolvedKey->type) {
                throw new \TypeError('Illegal offset type');
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_STRING === $resolvedKey->type) {
                $raw = $resolvedKey->toString();
                $newKey = StdlibConstants::CASE_LOWER === $case
                    ? VmString::asciiLower($raw)
                    : VmString::asciiUpper($raw);
                $out->add($newKey, $copy);
            } else {
                $out->addIndex($resolvedKey->toInt(), $copy);
            }
        }

        return $out;
    }

    /**
     * count() for arrays and Countable objects (Zend php_count parity, #3364).
     */
    public static function countValue(Context $ctx, Variable $value): int
    {
        $v = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY === $v->type) {
            return $v->toArray()->getNumElements();
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            $entry = $v->toObject()->class;
            if (!InterfaceCheck::entryImplements($entry, 'countable', $ctx)) {
                throw new \TypeError(
                    'count(): Argument #1 ($value) must be of type Countable|array, '
                    . $entry->name . ' given'
                );
            }
            $result = $ctx->runtime->vm->invokeInstanceMethod($v->toObject(), 'count')->resolveIndirect();
            if (Variable::TYPE_INTEGER !== $result->type) {
                throw new \TypeError(
                    'Return value of ' . $entry->name . '::count() must be of type int, '
                    . self::valueTypeLabel($result) . ' returned'
                );
            }

            return $result->toInt();
        }

        throw new \TypeError(
            'count(): Argument #1 ($value) must be of type Countable|array, '
            . self::valueTypeLabel($v) . ' given'
        );
    }

    private static function valueTypeLabel(Variable $value): string
    {
        $value = $value->resolveIndirect();
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($value);
        if (null !== $enumClass) {
            return $enumClass->name;
        }
        switch ($value->type) {
            case Variable::TYPE_INTEGER:
                return 'int';
            case Variable::TYPE_FLOAT:
                return 'float';
            case Variable::TYPE_BOOLEAN:
                return 'bool';
            case Variable::TYPE_STRING:
                return 'string';
            case Variable::TYPE_NULL:
                return 'null';
            case Variable::TYPE_ARRAY:
                return 'array';
            case Variable::TYPE_OBJECT:
                return $value->toObject()->class->name;
            default:
                return 'mixed';
        }
    }

    /**
     * @return list<array{0: Variable, 1: Variable}>
     */
    private static function copyKeyedPairs(HashTable $ht): array
    {
        $pairs = [];
        foreach ($ht->iterateKeyed(true) as [$key, $value]) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($key);
            $valCopy = new Variable();
            $valCopy->copyFrom($value);
            $pairs[] = [$keyCopy, $valCopy];
        }

        return $pairs;
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    private static function hashTableFromSortedPairs(array $pairs, string $function = 'ksort()'): HashTable
    {
        $sorted = new HashTable();
        foreach ($pairs as [$key, $value]) {
            $resolvedKey = $key->resolveIndirect();
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $resolvedKey->type) {
                $sorted->addIndex($resolvedKey->toInt(), $copy);
            } elseif (Variable::TYPE_STRING === $resolvedKey->type) {
                $sorted->add($resolvedKey->toString(), $copy);
            } else {
                throw new \LogicException(
                    $function.' only supports string or integer keys in this compiler build'
                );
            }
        }

        return $sorted;
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    private static function sortKeyedPairsByValue(
        array &$pairs,
        int $flags,
        string $function,
        bool $desc
    ): void {
        $values = array_map(static fn (array $pair): Variable => $pair[1], $pairs);
        if (VmInternalCompare::valuesAreEnumOrObjectOnly($values)) {
            VmInternalCompare::assertHomogeneousEnumOrObjectValues($values, $function);
            if ($desc) {
                VmInternalCompare::sortKeyedPairsByValueSpaceshipDesc($pairs);
            } else {
                VmInternalCompare::sortKeyedPairsByValueSpaceship($pairs);
            }

            return;
        }
        if ($desc) {
            VmInternalCompare::sortKeyedPairsByValueWithFlagsDesc($pairs, $flags);
        } else {
            VmInternalCompare::sortKeyedPairsByValueWithFlags($pairs, $flags);
        }
    }
}
