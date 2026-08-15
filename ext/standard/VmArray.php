<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\InterfaceCheck;
use PHPCompiler\VM\ResourceSupport;
use PHPCompiler\VM\Variable;
use PHPCompiler\VM\VmNumericCoercion;

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
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            return true;
        }
        $value = $value->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $value->type) {
            return false;
        }

        // Case singletons may lack isEnumCase on legacy materialization paths (#8828, #5578).
        return $value->toObject()->class->isEnum;
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
        if (is_numeric($s)) {
            if (((string) (int) $s) === $s
                && !str_contains($s, '.')
                && !str_contains(strtolower($s), 'e')) {
                return [(int) $s, false];
            }

            return [(float) $s, true];
        }

        // Zend zval_get_long/zval_get_double: leading-numeric strings like "3a" coerce
        // to their numeric prefix via (int)/(float) cast; fully non-numeric → 0.
        $intVal = (int) $s;
        if (0 !== $intVal || '0' === ($s[0] ?? '')) {
            return [$intVal, false];
        }

        return [0, false];
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
     * array_intersect/diff* reject enum case and non-stringable object operands (#5927, #11249, php-src array.c).
     */
    public static function rejectEnumCaseSetOpOperands(?Frame $frame, HashTable ...$tables): void
    {
        foreach ($tables as $table) {
            foreach ($table->iterateKeyed(true) as [$key, $value]) {
                self::rejectSetOpKeyOrValue($frame, $value);
                self::rejectSetOpKeyOrValue($frame, $key);
            }
        }
    }

    private static function rejectSetOpKeyOrValue(?Frame $frame, Variable $var): void
    {
        $var = $var->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
            throw new \Error(
                'Object of class '.($enumClass->name ?? 'enum').' could not be converted to string'
            );
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            return;
        }
        if (null !== $frame && null !== $frame->vmContext) {
            $frame->vmContext->runtime->vm->castObjectToString($var->toObject());

            return;
        }
        throw new \Error(
            'Object of class '.$var->toObject()->class->name.' could not be converted to string'
        );
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
     * array_combine()/array_fill_keys() key slot — Zend convert_to_key (ext/standard/array.c, #4161).
     *
     * Objects with __toString() stringify like zend_operators cast_object (#24035, #24036);
     * enum cases stay Error via {@see rejectEnumCaseKeyVariable()}.
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
            // Zend convert_to_string then hash update: true → "1" → int 1; false → "" (#24033/#24034).
            // Literal `$a[false]` still uses int 0 via normalizeIndexKey — only combine/fill_keys stringify.
            if ($key->toBool()) {
                $ht->updateIndex(1, $stored);
            } else {
                $ht->update('', $stored);
            }

            return;
        }
        if (Variable::TYPE_NULL === $key->type) {
            $ht->update('', $stored);

            return;
        }
        if (Variable::TYPE_OBJECT === $key->type) {
            $vm = null !== $frame && null !== $frame->vmContext
                ? $frame->vmContext->runtime->vm
                : VM::running();
            if (null === $vm) {
                throw new \Error(
                    'Object of class '.$key->toObject()->class->name.' could not be converted to string'
                );
            }
            // HashTable::update() applies numeric-string → int key (zend_hash; __toString "5" → 5).
            $ht->update($vm->castObjectToString($key->toObject()), $stored);

            return;
        }

        throw new \Error(
            'Object of class '.self::valueTypeLabel($key).' could not be converted to string'
        );
    }

    /**
     * natsort/natcasesort natural compare requires string operands — Zend rejects enum cases (#5607)
     * and objects (#12244).
     */
    public static function rejectEnumCaseNaturalSortValue(Variable $value): void
    {
        $value = $value->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($value)) {
            throw new \Error(
                'Object of class '.EnumCaseSupport::typeNameForVariable($value).' could not be converted to string'
            );
        }
        if (Variable::TYPE_OBJECT === $value->type) {
            throw new \Error(
                'Object of class '.$value->toObject()->class->name.' could not be converted to string'
            );
        }
    }

    /**
     * @param list<Variable> $values
     */
    private static function rejectEnumCaseNaturalSortOperands(array $values): void
    {
        foreach ($values as $value) {
            self::rejectEnumCaseNaturalSortValue($value);
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

    /**
     * array_diff() with one source array — copy keys/values (php-src array.c, issue #1206).
     */
    public static function diffSingleArgumentCopy(HashTable $first): HashTable
    {
        return $first->replaceCopy();
    }

    /**
     * array_diff() two-array step — remove from $first values found in $other (loose compare).
     */
    public static function diffTwo(HashTable $first, HashTable $other): HashTable
    {
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            if (self::looseValueInHashTable($value, $other)) {
                continue;
            }
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

    private static function looseValueInHashTable(Variable $needle, HashTable $haystack): bool
    {
        $needle = $needle->resolveIndirect();
        foreach ($haystack->iterate(true) as $value) {
            if (self::looseValuesEqualForArraySetOps($needle, $value->resolveIndirect())) {
                return true;
            }
        }

        return false;
    }

    /**
     * Loose value compare for array_diff/array_intersect (php-src zend_hash_compare NaN branch).
     */
    private static function looseValuesEqualForArraySetOps(Variable $left, Variable $right): bool
    {
        if (Variable::TYPE_FLOAT === $left->type
            && Variable::TYPE_FLOAT === $right->type
            && \is_nan($left->toFloat())
            && \is_nan($right->toFloat())) {
            return true;
        }

        return in_array::looseEquals($left, $right);
    }

    /**
     * array_intersect() with one source array — copy keys/values (php-src array.c, issue #1207).
     */
    public static function intersectSingleArgumentCopy(HashTable $first): HashTable
    {
        return $first->replaceCopy();
    }

    /**
     * array_intersect() two-array step — keep values from $first found in $other (loose compare).
     */
    public static function intersectTwo(HashTable $first, HashTable $other): HashTable
    {
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            if (!self::looseValueInHashTable($value, $other)) {
                continue;
            }
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
     * array_intersect_key() with one source array — copy keys/values (php-src array.c, #12551).
     */
    public static function intersectKeySingleArgumentCopy(HashTable $first): HashTable
    {
        return $first->replaceCopy();
    }

    /**
     * array_intersect_key() two-array step — keep entries whose keys exist in $other.
     */
    public static function intersectKeyTwo(HashTable $first, HashTable $other): HashTable
    {
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            if (!self::keyExistsInHashTable($key, $other)) {
                continue;
            }
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
     * array_diff_assoc() with one source array — copy keys/values (php-src array.c, #12552).
     */
    public static function diffAssocSingleArgumentCopy(HashTable $first): HashTable
    {
        return $first->replaceCopy();
    }

    /**
     * array_diff_assoc() two-array step — remove entries whose key+value pair exists in $other.
     */
    public static function diffAssocTwo(HashTable $first, HashTable $other): HashTable
    {
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            if (self::pairInHashTable($key, $value, $other)) {
                continue;
            }
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
     * array_intersect_assoc() with one source array — copy keys/values (php-src array.c, #12636).
     */
    public static function intersectAssocSingleArgumentCopy(HashTable $first): HashTable
    {
        return $first->replaceCopy();
    }

    /**
     * array_intersect_assoc() two-array step — keep entries whose key+value pair exists in $other.
     */
    public static function intersectAssocTwo(HashTable $first, HashTable $other): HashTable
    {
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            if (!self::pairInHashTable($key, $value, $other)) {
                continue;
            }
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
     * array_diff_key() with one source array — copy keys/values (php-src array.c, #12553).
     */
    public static function diffKeySingleArgumentCopy(HashTable $first): HashTable
    {
        return $first->replaceCopy();
    }

    /**
     * array_diff_key() two-array step — remove entries whose keys exist in $other.
     */
    public static function diffKeyTwo(HashTable $first, HashTable $other): HashTable
    {
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            if (self::keyExistsInHashTable($key, $other)) {
                continue;
            }
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

    private static function keyExistsInHashTable(Variable $key, HashTable $table): bool
    {
        return null !== self::valueAtKeyInHashTable($key, $table);
    }

    private static function pairInHashTable(Variable $key, Variable $value, HashTable $haystack): bool
    {
        $stored = self::valueAtKeyInHashTable($key, $haystack);
        if (null === $stored) {
            return false;
        }

        return $value->resolveIndirect()->equals($stored->resolveIndirect());
    }

    private static function valueAtKeyInHashTable(Variable $key, HashTable $table): ?Variable
    {
        $key = $key->resolveIndirect();
        if (Variable::TYPE_INTEGER === $key->type) {
            return $table->findIndex($key->toInt());
        }
        if (Variable::TYPE_FLOAT === $key->type) {
            return $table->findIndex($key->toInt());
        }
        if (Variable::TYPE_STRING === $key->type) {
            return $table->find($key->toString());
        }

        return null;
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
        if (Variable::TYPE_ARRAY === $v->type) {
            return $v->toArray();
        }
        if (Variable::TYPE_NULL === $v->type) {
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

    /**
     * Z_PARAM_ARRAY at internal call sites — always TypeError on null (php-src 8.0+;
     * Zend 8.2 reference). Soft-null/DEP was an inverted #21771 claim (#21916).
     *
     * {@param $frame} retained for call-site API stability; unused for the type check.
     *
     * @throws \TypeError when {@param $value} is not an array
     */
    public static function requireArrayParamForCaller(
        Frame $frame,
        Variable $value,
        string $fn,
        int $argNum,
        string $paramName,
        string $expectedType = 'array'
    ): HashTable {
        unset($frame);

        return self::requireArrayParam($value, $fn, $argNum, $paramName, $expectedType);
    }

    /**
     * @throws \TypeError when {@param $value} is not an array
     */
    public static function requireArrayForCaller(Frame $frame, Variable $value, string $fn): HashTable
    {
        return self::requireArrayParamForCaller($frame, $value, $fn, 1, 'array');
    }

    /**
     * Variadic array builtins (Zend messages omit ($param)) — always TypeError on null (#21916).
     *
     * {@param $frame} retained for call-site API stability; unused for the type check.
     *
     * @throws \TypeError when {@param $value} is not an array
     */
    public static function requireArrayArgNumForCaller(
        Frame $frame,
        Variable $value,
        string $fn,
        int $argNum
    ): HashTable {
        unset($frame);

        return self::requireArrayArgNum($value, $fn, $argNum);
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
     * Max elements array_pad() may append/prepend (php-src 8.2 Z_L(1048576)).
     *
     * php-src: ext/standard/array.c — PHP_FUNCTION(array_pad)
     * pad_size_abs - input_size > 1048576 → ValueError (#26658).
     */
    public const ARRAY_PAD_MAX_PAD_SIZE = 1048576;

    /**
     * array_pad() — pad packed list {@param $array} to abs({@param $length}) with {@param $value}.
     *
     * Padding direction follows the sign of {@param $length} (php-src ext/standard/array.c), or
     * optional {@param $padType} when PHP 8.4+ 4-arg form is used (#14993).
     *
     * Rejects oversized pad amounts before allocating (Zend 8.2 pad-size guard, #26658).
     */
    public static function pad(HashTable $array, int $length, Variable $value, ?int $padType = null): HashTable
    {
        self::rejectOversizedPad($array->getNumElements(), $length);

        return $array->padCopy($length, $value, $padType);
    }

    /**
     * Throw Zend ValueError when pad amount would exceed {@see ARRAY_PAD_MAX_PAD_SIZE}.
     *
     * php-src 8.2+: if (pad_size_abs - input_size > limit) / HT_MAX_SIZE check.
     * pad_size_abs < 0 covers ZEND_ABS(ZEND_LONG_MIN) overflow; in PHP abs(PHP_INT_MIN)
     * is a float, so treat PHP_INT_MIN as oversized.
     * Message matches Zend 8.4 abstract wording (#29342); numeric gate stays #26658.
     */
    public static function rejectOversizedPad(int $inputSize, int $length): void
    {
        // php-src wording (8.3+ / Zend 8.4): abstract limit text, not the raw 1048576 (#29342).
        // Numeric guard remains ARRAY_PAD_MAX_PAD_SIZE from #26658.
        $zendMsg = 'array_pad(): Argument #2 ($length) must not exceed the maximum allowed array size';
        if (\PHP_INT_MIN === $length) {
            throw new \ValueError($zendMsg);
        }
        $padSizeAbs = abs($length);
        if ($padSizeAbs - $inputSize > self::ARRAY_PAD_MAX_PAD_SIZE) {
            throw new \ValueError($zendMsg);
        }
    }

    /**
     * ArrayPadType pure enum → ARRAY_PAD_RIGHT / ARRAY_PAD_LEFT (#17240).
     */
    public static function tryArrayPadTypeInt(Variable $var): ?int
    {
        if (!EnumCaseSupport::isEnumCaseVariable($var)) {
            return null;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($var);
        if (null === $enumClass || !self::isArrayPadTypeEnum($enumClass->name)) {
            return null;
        }
        $entry = EnumCaseSupport::enumCaseEntryForVariable($var);
        if (null === $entry) {
            throw new \LogicException('ArrayPadType case missing');
        }

        return match ($entry->caseName) {
            'Positive' => StdlibConstants::ARRAY_PAD_RIGHT,
            'Negative' => StdlibConstants::ARRAY_PAD_LEFT,
            default => throw new \ValueError('Invalid ArrayPadType enum value'),
        };
    }

    private static function isArrayPadTypeEnum(string $className): bool
    {
        return 0 === strcasecmp(ltrim($className, '\\'), 'ArrayPadType');
    }

    /**
     * array_pad() 4th parameter pad type (PHP 8.4+, ext/standard/array.c, #14993).
     */
    public static function resolvePadTypeArg(Variable $var): int
    {
        $var = $var->resolveIndirect();
        $padFromEnum = self::tryArrayPadTypeInt($var);
        if (null !== $padFromEnum) {
            return $padFromEnum;
        }
        if (EnumCaseSupport::isEnumCaseVariable($var)) {
            throw new \TypeError(\sprintf(
                'array_pad(): Argument #4 ($pad_type) must be of type ArrayPadType|int, %s given',
                EnumCaseSupport::typeNameForVariable($var)
            ));
        }
        if (Variable::TYPE_INTEGER !== $var->type) {
            throw new \LogicException('array_pad() pad type must be an integer in this compiler build');
        }
        $padType = $var->toInt();
        if (!\in_array($padType, [
            StdlibConstants::ARRAY_PAD_LEFT,
            StdlibConstants::ARRAY_PAD_RIGHT,
            StdlibConstants::ARRAY_PAD_BOTH,
        ], true)) {
            throw new \ValueError(
                'array_pad(): Argument #4 ($pad_type) must be ARRAY_PAD_LEFT, ARRAY_PAD_RIGHT, or ARRAY_PAD_BOTH'
            );
        }

        return $padType;
    }

    /**
     * array_key_exists() / key_exists() — key present regardless of null value (#13735).
     */
    public static function keyExists(Variable $key, HashTable $table): bool
    {
        $key = $key->resolveIndirect();
        if (Variable::TYPE_NULL === $key->type) {
            $empty = new Variable();
            $empty->string('');

            return null !== self::valueAtKeyInHashTable($empty, $table);
        }
        if (Variable::TYPE_BOOLEAN === $key->type) {
            $intKey = new Variable();
            $intKey->int($key->toBool() ? 1 : 0);

            return null !== self::valueAtKeyInHashTable($intKey, $table);
        }

        return null !== self::valueAtKeyInHashTable($key, $table);
    }

    /**
     * in_array() — scan {@param $haystack} for {@param $needle} (ext/standard/array.c).
     */
    public static function contains(Variable $needle, HashTable $haystack, bool $strict): bool
    {
        $needle = $needle->resolveIndirect();
        $vm = \PHPCompiler\VM::running();
        foreach ($haystack->iterate(true) as $value) {
            $stored = $value->resolveIndirect();
            if ($strict ? $needle->identicalTo($stored) : $needle->equals($stored, $vm)) {
                return true;
            }
        }

        return false;
    }

    /**
     * array_search() — first matching key or false (ext/standard/array.c).
     */
    public static function searchKey(
        Variable $needle,
        HashTable $haystack,
        bool $strict,
        ?\PHPCompiler\VM $vm = null
    ): Variable {
        $needle = $needle->resolveIndirect();
        foreach ($haystack->iterateKeyed(true) as [$key, $value]) {
            if ($strict ? $needle->identicalTo($value) : $needle->equals($value, $vm)) {
                $result = new Variable();
                $result->copyFrom($key);

                return $result;
            }
        }
        $false = new Variable();
        $false->bool(false);

        return $false;
    }

    /**
     * array_combine() — keys from {@param $keys}, values from {@param $values} (ext/standard/array.c).
     *
     * @throws \ValueError when operand lengths differ (non-empty mismatch)
     */
    public static function combine(HashTable $keys, HashTable $values, ?Frame $frame = null): HashTable
    {
        $keyList = [];
        foreach ($keys->iterateKeyed(true) as [, $key]) {
            $keyList[] = $key;
        }
        $valList = [];
        foreach ($values->iterateKeyed(true) as [, $value]) {
            $valList[] = $value;
        }
        if (0 === \count($keyList) && 0 === \count($valList)) {
            return new HashTable();
        }
        if (\count($keyList) !== \count($valList)) {
            throw new \ValueError(array_combine::LENGTH_MISMATCH_ERROR);
        }
        $ht = new HashTable();
        $n = \count($keyList);
        for ($i = 0; $i < $n; ++$i) {
            $stored = new Variable();
            $stored->copyFrom($valList[$i]);
            self::storeCombineKey($ht, $keyList[$i], $stored, $frame);
        }

        return $ht;
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

    /** asort() — return array sorted by value ascending, preserving keys (#11991). */
    public static function asortCopy(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $pairs = self::copyKeyedPairs($ht);
        self::sortKeyedPairsByValue($pairs, $flags, 'asort()', false);
        return self::hashTableFromSortedPairs($pairs);
    }

    /** natsort() — return array sorted by value using natural order; preserves keys (#9600, #29691). */
    public static function natsortCopy(HashTable $ht): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $pairs = self::copyKeyedPairs($ht);
        $values = array_map(static fn (array $pair): Variable => $pair[1], $pairs);
        self::rejectEnumCaseNaturalSortOperands($values);
        if (VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_STRING)) {
            VmInternalCompare::sortKeyedPairsByValue(
                $pairs,
                VmInternalCompare::resolveStringCallback('strnatcmp')
            );
        } elseif (VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_INTEGER)) {
            VmInternalCompare::sortKeyedPairsByValueInt($pairs);
        } else {
            // Mixed types (null/bool/… + strings): php-src php_natsort always uses natural
            // string compare after printable coercion — not SORT_REGULAR (#29691).
            VmInternalCompare::sortKeyedPairsByValueWithFlags($pairs, StdlibConstants::SORT_NATURAL);
        }

        return self::hashTableFromSortedPairs($pairs);
    }

    /** natcasesort() — return array sorted by value using natural case-insensitive order (#2372, #9600, #29704). */
    public static function natcasesortCopy(HashTable $ht): HashTable
    {
        if ($ht->getNumElements() < 2) {
            return $ht;
        }
        $pairs = self::copyKeyedPairs($ht);
        $values = array_map(static fn (array $pair): Variable => $pair[1], $pairs);
        self::rejectEnumCaseNaturalSortOperands($values);
        if (VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_STRING)) {
            VmInternalCompare::sortKeyedPairsByValue(
                $pairs,
                VmInternalCompare::resolveStringCallback('strnatcasecmp')
            );
        } elseif (VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_INTEGER)) {
            VmInternalCompare::sortKeyedPairsByValueInt($pairs);
        } else {
            // Mixed types (null/bool/… + strings): php-src php_natcasesort always uses
            // natural case-insensitive string compare after printable coercion (#29704).
            VmInternalCompare::sortKeyedPairsByValueWithFlags(
                $pairs,
                StdlibConstants::SORT_NATURAL | StdlibConstants::SORT_FLAG_CASE
            );
        }

        return self::hashTableFromSortedPairs($pairs);
    }

    /** arsort() — return array sorted by value descending, preserving keys (#11991). */
    public static function arsortCopy(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): HashTable
    {
        if ($ht->getNumElements() < 2) {
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
        self::shufflePackedWithPicker($ht, static fn (int $upper): int => self::randomIndexBelow($upper));
    }

    /**
     * Fisher–Yates shuffle using a caller-supplied index picker (Random\Randomizer::shuffleArray()).
     *
     * {@param $pickIndex} receives the exclusive upper bound and returns an index in [0, upper).
     */
    public static function shufflePackedWithPicker(HashTable $ht, callable $pickIndex): void
    {
        $n = $ht->getNumElements();
        if (0 === $n) {
            return;
        }
        $values = self::copyPackedValues($ht);
        if ($n >= 2) {
            self::fisherYatesShuffleVariablesWithPicker($values, $pickIndex);
        }
        self::writeReindexedValues($ht, $values);
    }

    /**
     * sort()/rsort()/usort()/shuffle() assign new keys 0..n-1 (php-src php_array_sort).
     *
     * Early-exit paths for n<2 must still reindex single-element non-list arrays (#25385).
     */
    public static function reindexToListKeys(HashTable $ht): void
    {
        $n = $ht->getNumElements();
        if (0 === $n || self::isList($ht)) {
            return;
        }
        self::writeReindexedValues($ht, self::copyPackedValues($ht));
    }

    /**
     * @param list<Variable> $values
     */
    public static function writeReindexedValues(HashTable $ht, array $values): void
    {
        if (self::isList($ht)) {
            $ht->replacePackedValues($values);
        } else {
            $ht->assignPackedList($values);
        }
    }

    /** sort() on packed list — reindex 0..n-1 (#12769, php-src php_array_sort). */
    public static function sortPackedInPlace(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): void
    {
        $n = $ht->getNumElements();
        if (0 === $n) {
            return;
        }
        $values = self::copyPackedValues($ht);
        if ($n >= 2) {
            self::sortPackedValues($values, $flags, 'sort()', false);
        }
        self::writeReindexedValues($ht, $values);
    }

    /** rsort() on packed list — reindex descending (#12769). */
    public static function sortPackedReverseInPlace(HashTable $ht, int $flags = StdlibConstants::SORT_REGULAR): void
    {
        $n = $ht->getNumElements();
        if (0 === $n) {
            return;
        }
        $values = self::copyPackedValues($ht);
        if ($n >= 2) {
            self::sortPackedValues($values, $flags, 'rsort()', true);
        }
        self::writeReindexedValues($ht, $values);
    }

    /**
     * array_rand() — pick {@param $num} unique keys (php-src php_array_pick_keys / MT19937; #2321, #14271).
     */
    public static function arrayRandPacked(HashTable $ht, int $num): Variable
    {
        $numAvail = $ht->getNumElements();
        if (0 === $numAvail) {
            throw new \ValueError('array_rand(): Argument #1 ($array) must not be empty');
        }
        if ($num < 1 || $num > $numAvail) {
            throw new \ValueError(
                'array_rand(): Argument #2 ($num) must be between 1 and the number of elements in argument #1 ($array)'
            );
        }

        return self::pickKeysWithPicker(
            $ht,
            $num,
            static fn (int $min, int $max): int => VmMt19937::range($min, $max),
            false
        );
    }

    /**
     * php-src php_array_pick_keys — bitset sample via {@param $range}(min, max).
     *
     * @param callable(int, int): int $range
     * @param bool                    $alwaysArray When true (Randomizer::pickArrayKeys), wrap a single key in a list (#3722).
     */
    public static function pickKeysWithPicker(HashTable $ht, int $num, callable $range, bool $alwaysArray = false): Variable
    {
        $numAvail = $ht->getNumElements();
        $keys = [];
        foreach ($ht->iterateKeyed() as $pair) {
            $keyCopy = new Variable();
            $keyCopy->copyFrom($pair[0]);
            $keys[] = $keyCopy;
        }

        if (1 === $num) {
            $idx = $range(0, $numAvail - 1);
            $one = new Variable();
            $one->copyFrom($keys[$idx]);
            if (!$alwaysArray) {
                return $one;
            }
            $arr = new HashTable();
            $arr->addIndex(0, $one);
            $result = new Variable();
            $result->array($arr);

            return $result;
        }

        $numReq = $num;
        $negativeBitset = false;
        if ($numReq > ($numAvail >> 1)) {
            $negativeBitset = true;
            $numReq = $numAvail - $numReq;
        }

        /** @var list<bool> $bitset */
        $bitset = \array_fill(0, $numAvail, false);
        $remaining = $numReq;
        $failures = 0;
        while ($remaining > 0) {
            $randval = $range(0, $numAvail - 1);
            if ($bitset[$randval]) {
                if (++$failures > 50) {
                    throw new \Random\BrokenRandomEngineError(
                        'Failed to generate an acceptable random number in 50 attempts'
                    );
                }
                continue;
            }
            $bitset[$randval] = true;
            --$remaining;
            $failures = 0;
        }

        $result = new Variable();
        $arr = new HashTable();
        $outIdx = 0;
        for ($i = 0; $i < $numAvail; ++$i) {
            if ($bitset[$i] xor $negativeBitset) {
                $v = new Variable();
                $v->copyFrom($keys[$i]);
                $arr->addIndex($outIdx++, $v);
            }
        }
        $result->array($arr);

        return $result;
    }

    /**
     * @param list<Variable> $values
     */
    private static function fisherYatesShuffleVariables(array &$values): void
    {
        self::fisherYatesShuffleVariablesWithPicker(
            $values,
            static fn (int $upper): int => self::randomIndexBelow($upper)
        );
    }

    /**
     * @param list<Variable> $values
     */
    private static function fisherYatesShuffleVariablesWithPicker(array &$values, callable $pickIndex): void
    {
        $n = \count($values);
        for ($i = $n - 1; $i > 0; --$i) {
            $j = $pickIndex($i + 1);
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
        return self::countRecursiveWalk($ht, $frame, $visited, false);
    }

    /** count(COUNT_RECURSIVE) for compiled JIT/AOT — warnings via compiler_language_warning (#13274). */
    public static function countRecursiveForCompiled(HashTable $ht, ?\SplObjectStorage $visited = null): int
    {
        return self::countRecursiveWalk($ht, null, $visited, true);
    }

    private static function countRecursiveWalk(
        HashTable $ht,
        ?Frame $frame,
        ?\SplObjectStorage $visited,
        bool $compiledWarning
    ): int {
        if (null === $visited) {
            $visited = new \SplObjectStorage();
        }
        if ($visited->contains($ht)) {
            if ($compiledWarning) {
                compiler_language_warning(self::COUNT_RECURSIVE_WARNING);
            } else {
                self::countRecursiveWarning($frame);
            }

            return 0;
        }
        $visited->attach($ht);
        try {
            $count = $ht->getNumElements();
            foreach ($ht->iterate(true) as $value) {
                if (Variable::TYPE_ARRAY === $value->type) {
                    $count += self::countRecursiveWalk($value->toArray(), $frame, $visited, $compiledWarning);
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
    public static function countValue(Context $ctx, Variable $value, string $fn = 'count'): int
    {
        $v = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY === $v->type) {
            return $v->toArray()->getNumElements();
        }
        if (Variable::TYPE_OBJECT === $v->type) {
            $entry = $v->toObject()->class;
            if (!InterfaceCheck::entryImplements($entry, 'countable', $ctx)) {
                throw new \TypeError(
                    $fn.'(): Argument #1 ($value) must be of type Countable|array, '
                    . $entry->name . ' given'
                );
            }
            // php-src zend_call_method + zend_verify_return_type: typed count() under
            // declare(strict_types=1) TypeErrors; weak/untyped still coerce via zval_get_long (#26433).
            // Do not suppress return checks (#12867 was untyped scalar returns only).
            $result = $ctx->runtime->vm->invokeInstanceMethod(
                $v->toObject(),
                'count'
            )->resolveIndirect();

            return VmNumericCoercion::zvalGetLong($result);
        }

        throw new \TypeError(
            $fn.'(): Argument #1 ($value) must be of type Countable|array, '
            . self::valueTypeLabel($v) . ' given'
        );
    }

    private static function valueTypeLabel(Variable $value): string
    {
        // php-src zend_argument_type_error / Z_PARAM_* — zend_zval_type_name (#30480).
        // GH-8385: "bool" on unset/PROFILE≤8.3; true/false on PROFILE≥8.4 (#31160).
        return EnumCaseSupport::typeNameForTypeErrorActual($value);
    }

    /**
     * @return list<Variable>
     */
    private static function copyPackedValues(HashTable $ht): array
    {
        $values = [];
        foreach ($ht->iterate(true) as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $values[] = $copy;
        }

        return $values;
    }

    /**
     * @param list<Variable> $values
     */
    private static function sortPackedValues(array &$values, int $flags, string $function, bool $desc): void
    {
        if (\count($values) < 2) {
            return;
        }
        $first = $values[0]->resolveIndirect();
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (Variable::TYPE_STRING === $first->type) {
            if (
                StdlibConstants::SORT_STRING === $sortType
                && VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_STRING)
            ) {
                $cmp = VmInternalCompare::stringCompareForSortFlags($flags);
                if ($desc) {
                    VmInternalCompare::sortVariableValuesDesc($values, $cmp);
                } else {
                    VmInternalCompare::sortVariableValues($values, $cmp);
                }
            } else {
                if ($desc) {
                    VmInternalCompare::sortVariableValuesWithFlagsDesc($values, $flags);
                } else {
                    VmInternalCompare::sortVariableValuesWithFlags($values, $flags);
                }
            }
        } elseif (Variable::TYPE_INTEGER === $first->type) {
            if (
                StdlibConstants::SORT_REGULAR === $sortType
                && VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_INTEGER)
            ) {
                $n = \count($values);
                for ($i = 1; $i < $n; ++$i) {
                    $j = $i;
                    while ($j > 0) {
                        $a = $values[$j - 1]->resolveIndirect();
                        $b = $values[$j]->resolveIndirect();
                        $ordered = $desc ? ($a->toInt() >= $b->toInt()) : ($a->toInt() <= $b->toInt());
                        if ($ordered) {
                            break;
                        }
                        $tmp = $values[$j - 1];
                        $values[$j - 1] = $values[$j];
                        $values[$j] = $tmp;
                        --$j;
                    }
                }
            } else {
                if ($desc) {
                    VmInternalCompare::sortVariableValuesWithFlagsDesc($values, $flags);
                } else {
                    VmInternalCompare::sortVariableValuesWithFlags($values, $flags);
                }
            }
        } elseif (Variable::TYPE_OBJECT === $first->type || EnumCaseSupport::isEnumCaseVariable($first)) {
            VmInternalCompare::assertHomogeneousEnumOrObjectValues($values, $function);
            if (!self::packedObjectSortUsesSpaceship($flags)) {
                throw new \LogicException(
                    $function.' flags are not supported for object arrays in this compiler build'
                );
            }
            if ($desc) {
                VmInternalCompare::sortVariableValuesBySpaceshipDesc($values);
            } else {
                VmInternalCompare::sortVariableValuesBySpaceship($values);
            }
        } else {
            if ($desc) {
                VmInternalCompare::sortVariableValuesWithFlagsDesc($values, $flags);
            } else {
                VmInternalCompare::sortVariableValuesWithFlags($values, $flags);
            }
        }
    }

    /** php-src php_array_sort — SORT_REGULAR uses zend_compare on object zvals. */
    private static function packedObjectSortUsesSpaceship(int $flags): bool
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;

        return StdlibConstants::SORT_REGULAR === $sortType
            || StdlibConstants::SORT_NUMERIC === $sortType;
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
