<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\VM;

use php\MaskedArray;

final class HashTable {
    const OKAY                     = 0b0000000;
    const IS_DESTROYING            = 0b0000001;
    const DESTROYED                = 0b0000010;
    const CLEANING                 = 0b0000011;
    const FLAG_CONSISTENCY         = 0b0000011;
    const FLAG_PACKED              = 0b0000100;
    const FLAG_UNINITIALIZED       = 0b0001000;
    const FLAG_STATIC_KEYS         = 0b0010000;
    const FLAG_HAS_EMPTY_IND       = 0b0100000;
    const FLAG_ALLOW_COW_VIOLATION = 0b1000000;

    const MIN_SIZE = 3; // 2^3 or 8
    const INVALID_INDEX = -1;

    const UPDATE          = 0b000001;
    const ADD             = 0b000010;
    const UPDATE_INDIRECT = 0b000100;
    const ADD_NEW         = 0b001000;
    const ADD_NEXT        = 0b010000;

    private Refcount $refcount;
    private int $flags = 0;
    private MaskedArray $indexes;
    private MaskedArray $buckets;
    private int $numUsed = 0;
    private int $numElements = 0;
    private int $internalPointer = 0;
    private int $nextFreeElement = 0;


    public function __construct() {
        $this->refcount = new Refcount;
        $this->flags = self::FLAG_UNINITIALIZED;
        $this->indexes = MaskedArray::allocate(self::MIN_SIZE);
        $this->buckets = MaskedArray::allocate(self::MIN_SIZE);
        HashTableRegistry::register($this);
    }

    public function addRef(): void {
        $this->refcount->addRef();
    }

    public function delRef(): void {
        $this->refcount->delRef();
        if (0 === $this->refcount->refcount && !$this->isDestroyed() && !CycleCollector::isGcProtected()) {
            HashTableRegistry::release($this);
        }
    }

    public function isDestroyed(): bool
    {
        return self::DESTROYED === ($this->flags & self::FLAG_CONSISTENCY);
    }

    /** Break bucket edges after cycle collection (#13400). */
    public function destroyForGc(): void
    {
        if ($this->isDestroyed()) {
            return;
        }
        $this->flags = ($this->flags & ~self::FLAG_CONSISTENCY) | self::IS_DESTROYING;
        for ($i = 0; $i < $this->numUsed; ++$i) {
            $bucket = $this->buckets->read($i);
            if (!$bucket->value->isUndefined()) {
                $bucket->value->reset();
            }
        }
        $this->flags = ($this->flags & ~self::FLAG_CONSISTENCY) | self::DESTROYED;
    }

    public function needsSeparate(): bool {
        if ($this->flags & self::FLAG_ALLOW_COW_VIOLATION) {
            return false;
        }

        return $this->refcount->needsSeparate();
    }

    /** Stream/resource handles (e.g. stream-context) mutate in place like Zend resources (#6367). */
    public function markResourceLikeHandle(): void
    {
        $this->flags |= self::FLAG_ALLOW_COW_VIOLATION;
    }

    public function isResourceLikeHandle(): bool
    {
        return 0 !== ($this->flags & self::FLAG_ALLOW_COW_VIOLATION);
    }

    private function assertSeparatedForWrite(): void
    {
        if ($this->flags & self::FLAG_ALLOW_COW_VIOLATION) {
            return;
        }
        $this->refcount->assertSeparated();
    }

    /** Zend GC refcount for debug_zval_dump() (#6576). */
    public function getGcRefcount(): int
    {
        return $this->refcount->refcount;
    }

    /**
     * Deep copy for copy-on-write separation (Zend zend_array_dup).
     */
    public function duplicate(): self
    {
        $out = new self();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            return $out;
        }
        $maxIntKey = 0;
        foreach ($this->iterateKeyed(false) as [$key, $value]) {
            if (Variable::TYPE_INTEGER === $key->type) {
                $intKey = $key->toInt();
                if ($intKey > $maxIntKey) {
                    $maxIntKey = $intKey;
                }
            }
        }
        if ($maxIntKey > 0) {
            $out->ensureHashSlotCapacity($maxIntKey);
        }
        foreach ($this->iterateKeyed(false) as [$key, $value]) {
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->insertDuplicatedIndex($key->toInt(), $value);
            } else {
                $out->insertDuplicatedKey($key->toString(), $value);
            }
        }
        $out->internalPointer = $this->internalPointer;

        return $out;
    }

    /**
     * Insert a bucket during zend_array_dup without copyFrom() unwrapping IS_INDIRECT cells (#6727).
     */
    private function insertDuplicatedIndex(int $index, Variable $data): void
    {
        $this->assertConsistent();
        $this->assertSeparatedForWrite();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            $this->initMixed();
        }
        $this->resizeIfFull();
        $id = $this->numUsed++;
        $this->numElements++;
        $bucket = $this->buckets->read($id);
        $bucket->key = null;
        $bucket->hash = $index;
        $bucket->value->next = $this->indexes->read($index);
        $this->indexes->write($index, $id);
        $bucket->value->duplicateFrom($data);
        if ($index >= $this->nextFreeElement) {
            $this->nextFreeElement = $index + 1;
        }
    }

    private function insertDuplicatedKey(string $key, Variable $data): void
    {
        $intKey = self::tryIntFromNumericString($key);
        if (null !== $intKey) {
            $this->insertDuplicatedIndex($intKey, $data);

            return;
        }
        $this->assertConsistent();
        $this->assertSeparatedForWrite();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            $this->initMixed();
        }
        $this->resizeIfFull();
        $hash = $this->hash($key);
        $id = $this->numUsed++;
        $this->numElements++;
        $bucket = $this->buckets->read($id);
        $bucket->key = $key;
        $bucket->hash = $hash;
        $bucket->value->next = $this->indexes->read($hash);
        $this->indexes->write($hash, $id);
        $bucket->value->duplicateFrom($data);
    }

    public function iterate(bool $resolveIndirect = false): \Traversable {
        $values = [];
        for ($i = 0; $i < $this->numUsed; $i++) {
            $bucket = $this->buckets->read($i);
            if ($bucket->value->isUndefined()) {
                continue;
            }
            $value = $bucket->value;
            if ($resolveIndirect) {
                $value = $value->resolveIndirect();
            }
            $values[] = $value;
        }

        return new \ArrayIterator($values);
    }

    /**
     * @return \Traversable
     */
    public function iterateKeyed(bool $resolveIndirect = false): \Traversable
    {
        $pairs = [];
        for ($i = 0; $i < $this->numUsed; ++$i) {
            $bucket = $this->buckets->read($i);
            if ($bucket->value->isUndefined()) {
                continue;
            }
            $keyVar = new Variable();
            if (null !== $bucket->key) {
                $keyVar->string($bucket->key);
            } else {
                $keyVar->int($bucket->hash);
            }
            $value = $bucket->value;
            if ($resolveIndirect) {
                $value = $value->resolveIndirect();
            }
            $pairs[] = [$keyVar, $value];
        }

        return new \ArrayIterator($pairs);
    }

    /**
     * Materialize key/value pairs for JIT/AOT nested helper foreach (#12908).
     *
     * Prefer over iterateKeyed() in compiled php-in-PHP helpers — nested JIT lowers array
     * foreach but not HashTable::iterateKeyed() yet.
     *
     * @return list<array{Variable, Variable}>
     */
    public function exportKeyValuePairs(bool $resolveIndirect = false): array
    {
        $pairs = [];
        for ($i = 0; $i < $this->numUsed; ++$i) {
            $bucket = $this->buckets->read($i);
            if ($bucket->value->isUndefined()) {
                continue;
            }
            $keyVar = new Variable();
            if (null !== $bucket->key) {
                $keyVar->string($bucket->key);
            } else {
                $keyVar->int($bucket->hash);
            }
            $value = $bucket->value;
            if ($resolveIndirect) {
                $value = $value->resolveIndirect();
            }
            $pairs[] = [$keyVar, $value];
        }

        return $pairs;
    }

    public function iterReset(): void
    {
        $this->internalPointer = self::INVALID_INDEX;
    }

    public function iterValid(): bool
    {
        while (++$this->internalPointer < $this->numUsed) {
            if (!$this->buckets->read($this->internalPointer)->value->isUndefined()) {
                return true;
            }
        }

        return false;
    }

    public function iterCurrentKey(): Variable
    {
        $bucket = $this->buckets->read($this->internalPointer);
        $keyVar = new Variable();
        if (null !== $bucket->key) {
            $keyVar->string($bucket->key);
        } else {
            $keyVar->int($bucket->hash);
        }

        return $keyVar;
    }

    public function iterCurrentValue(bool $byRef = false): Variable
    {
        $bucket = $this->buckets->read($this->internalPointer);
        if ($byRef) {
            return $bucket->value;
        }
        // Zend ZEND_FE_FETCH_R: by-value foreach copies referenced slots in place (#5419).
        if ($bucket->value->isIndirect()) {
            $unref = new Variable();
            $unref->copyFrom($bucket->value->resolveIndirect());
            $bucket->value->copyFrom($unref);
        }
        $result = new Variable();
        $result->copyFrom($bucket->value->resolveIndirect());

        return $result;
    }

    /**
     * Zend array internal pointer — key() (ext/standard/array.c; #4967).
     */
    public function pointerKey(): ?Variable
    {
        if (!$this->pointerIsValid()) {
            return null;
        }

        return $this->iterCurrentKey();
    }

    /**
     * Zend array internal pointer — current()/pos() (ext/standard/array.c; #4967).
     */
    public function pointerCurrent(): ?Variable
    {
        if (!$this->pointerIsValid()) {
            return null;
        }

        return $this->iterCurrentValue();
    }

    /**
     * Zend array internal pointer — next() (ext/standard/array.c; #4967).
     */
    public function pointerNext(): ?Variable
    {
        if (0 === $this->numElements) {
            $this->internalPointer = self::INVALID_INDEX;

            return null;
        }
        if ($this->internalPointer >= $this->numUsed) {
            return null;
        }
        $start = self::INVALID_INDEX === $this->internalPointer
            ? 0
            : ($this->pointerIsValid() ? $this->internalPointer + 1 : $this->internalPointer + 1);
        $idx = $this->nextUsedBucketIndex($start);
        if (self::INVALID_INDEX === $idx) {
            $this->internalPointer = $this->numUsed;

            return null;
        }
        $this->internalPointer = $idx;

        return $this->iterCurrentValue();
    }

    /**
     * Zend array internal pointer — prev() (ext/standard/array.c; #4967).
     */
    public function pointerPrev(): ?Variable
    {
        if (0 === $this->numElements) {
            $this->internalPointer = self::INVALID_INDEX;

            return null;
        }
        if ($this->internalPointer >= $this->numUsed) {
            return null;
        }
        if (self::INVALID_INDEX === $this->internalPointer) {
            return null;
        }
        $before = $this->pointerIsValid() ? $this->internalPointer - 1 : $this->internalPointer - 1;
        $idx = $this->prevUsedBucketIndex($before);
        if (self::INVALID_INDEX === $idx) {
            $this->internalPointer = self::INVALID_INDEX;

            return null;
        }
        $this->internalPointer = $idx;

        return $this->iterCurrentValue();
    }

    /**
     * Zend array internal pointer — reset() (ext/standard/array.c; #4967).
     */
    public function pointerReset(): ?Variable
    {
        $idx = $this->nextUsedBucketIndex(0);
        if (self::INVALID_INDEX === $idx) {
            $this->internalPointer = self::INVALID_INDEX;

            return null;
        }
        $this->internalPointer = $idx;

        return $this->iterCurrentValue();
    }

    /**
     * Zend array internal pointer — end() (ext/standard/array.c; #4967).
     */
    public function pointerEnd(): ?Variable
    {
        $idx = $this->prevUsedBucketIndex($this->numUsed - 1);
        if (self::INVALID_INDEX === $idx) {
            $this->internalPointer = self::INVALID_INDEX;

            return null;
        }
        $this->internalPointer = $idx;

        return $this->iterCurrentValue();
    }

    private function pointerIsValid(): bool
    {
        if ($this->internalPointer < 0 || $this->internalPointer >= $this->numUsed) {
            return false;
        }

        return !$this->buckets->read($this->internalPointer)->value->isUndefined();
    }

    private function nextUsedBucketIndex(int $start): int
    {
        for ($i = $start; $i < $this->numUsed; ++$i) {
            if (!$this->buckets->read($i)->value->isUndefined()) {
                return $i;
            }
        }

        return self::INVALID_INDEX;
    }

    private function prevUsedBucketIndex(int $start): int
    {
        for ($i = $start; $i >= 0; --$i) {
            if (!$this->buckets->read($i)->value->isUndefined()) {
                return $i;
            }
        }

        return self::INVALID_INDEX;
    }

    /**
     * Zend zend_hash numeric-string key coercion (zend_hash.c; issue #3679).
     * Canonical decimal strings (e.g. "1", "-2") map to int keys; "01" and "foo" do not.
     */
    public static function tryIntFromNumericString(string $key): ?int
    {
        if ('' === $key || !preg_match('/^-?\d+$/', $key)) {
            return null;
        }
        $int = (int) $key;
        if ((string) $int !== $key) {
            return null;
        }

        return $int;
    }

    /**
     * php-src: null array keys coerce to empty string; bool keys to int (zend_hash.c; #5269, #5275).
     */
    public static function normalizeIndexKey(Variable $index, string $illegalOffsetMessage = 'Illegal offset type'): Variable
    {
        if (Variable::TYPE_INDIRECT === $index->type) {
            $index = $index->resolveIndirect();
        }
        EnumCaseSupport::rejectIllegalArrayOffset($index, $illegalOffsetMessage);
        if (Variable::TYPE_NULL === $index->type) {
            $empty = new Variable();
            $empty->string('');

            return $empty;
        }
        if (Variable::TYPE_BOOLEAN === $index->type) {
            $intKey = new Variable();
            $intKey->int($index->toBool() ? 1 : 0);

            return $intKey;
        }

        return $index;
    }

    public function keyExists(Variable $index): bool
    {
        $index = self::normalizeIndexKey($index);
        switch ($index->type) {
            case Variable::TYPE_INTEGER:
                return null !== $this->findIndex($index->toInt());
            case Variable::TYPE_FLOAT:
                return null !== $this->findIndex($index->toInt());
            case Variable::TYPE_STRING:
                return null !== $this->findByStringKey($index->toString());
            default:
                throw new \LogicException("Unknown index type {$index->type}");
        }
    }

    public function findVariable(Variable $index, bool $forWrite): ?Variable {
        $index = self::normalizeIndexKey($index);
        switch ($index->type) {
            case Variable::TYPE_INTEGER:
                $result = $this->findIndex($index->toInt());
                break;
            case Variable::TYPE_FLOAT:
                $result = $this->findIndex($index->toInt());
                break;
            case Variable::TYPE_STRING:
                $result = $this->findByStringKey($index->toString());
                break;
            default:
                throw new \LogicException("Unknown index type {$index->type}");
        }
        if (is_null($result)) {
            $result = new Variable;
            if ($forWrite) {
                if ($index->type === Variable::TYPE_INTEGER || $index->type === Variable::TYPE_FLOAT) {
                    return $this->addIndex($index->toInt(), $result);
                }
                $keyStr = $index->toString();
                $intKey = self::tryIntFromNumericString($keyStr);
                if (null !== $intKey) {
                    return $this->addIndex($intKey, $result);
                }

                return $this->add($keyStr, $result);
            }
        }
        return $result;
    }

    public function findIndex(int $index): ?Variable {
        $this->assertConsistent();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            return null;
        }
        $bucket = $this->findBucket($index, null);
        if (is_null($bucket)) {
            return null;
        }
        return $bucket->value;
    }

    public function find(string $key): ?Variable {
        return $this->findByStringKey($key);
    }

    private function findByStringKey(string $key): ?Variable
    {
        $this->assertConsistent();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            return null;
        }
        $intKey = self::tryIntFromNumericString($key);
        if (null !== $intKey) {
            $bucket = $this->findBucket($intKey, null);
            if (null !== $bucket) {
                return $bucket->value;
            }
        }
        $bucket = $this->findBucket($this->hash($key), $key);
        if (is_null($bucket)) {
            return null;
        }

        return $bucket->value;
    }

    public function getNumElements(): int
    {
        return $this->numElements;
    }

    /**
     * Zend zend_compare_arrays() parity for spaceship (<=>).
     */
    public function compareSpaceship(self $other): int
    {
        $leftCount = $this->getNumElements();
        $rightCount = $other->getNumElements();
        if ($leftCount > $rightCount) {
            return 1;
        }
        if ($leftCount < $rightCount) {
            return -1;
        }

        $leftItems = iterator_to_array($this->iterateKeyed(true));
        $rightItems = iterator_to_array($other->iterateKeyed(true));
        for ($i = 0, $n = \count($leftItems); $i < $n; ++$i) {
            [$leftKey, $leftVal] = $leftItems[$i];
            [$rightKey, $rightVal] = $rightItems[$i];
            $keyCmp = Variable::spaceshipCompare($leftKey, $rightKey);
            if (0 !== $keyCmp) {
                return $keyCmp;
            }
            $valCmp = Variable::spaceshipCompare($leftVal, $rightVal);
            if (0 !== $valCmp) {
                return $valCmp;
            }
        }

        return 0;
    }

    /**
     * Zend zend_compare_arrays() parity for loose == (compare_function returns 0).
     */
    public function compareLooseEqual(self $other): bool
    {
        if ($this->getNumElements() !== $other->getNumElements()) {
            return false;
        }

        $leftItems = iterator_to_array($this->iterateKeyed(true));
        $rightItems = iterator_to_array($other->iterateKeyed(true));
        for ($i = 0, $n = \count($leftItems); $i < $n; ++$i) {
            [$leftKey, $leftVal] = $leftItems[$i];
            [$rightKey, $rightVal] = $rightItems[$i];
            if (!$leftKey->equals($rightKey)) {
                return false;
            }
            if (!$leftVal->equals($rightVal)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Remove and return the last element of a packed list array (no holes).
     * Returns null when the array is empty.
     */
    public function popLast(): ?Variable
    {
        $this->assertConsistent();
        if (0 === $this->numElements) {
            return null;
        }
        if (!$this->isWithoutHoles()) {
            throw new \LogicException('popLast() only supports packed list arrays without holes');
        }
        $this->assertSeparatedForWrite();
        $lastSlot = $this->numUsed - 1;
        $bucket = $this->buckets->read($lastSlot);
        $result = new Variable();
        $result->copyFrom($bucket->value->resolveIndirect());
        --$this->numUsed;
        --$this->numElements;
        if ($this->isPackedList()) {
            --$this->nextFreeElement;
        } else {
            $this->recalcNextFreeElementFromBuckets();
        }
        $this->rehash();

        return $result;
    }

    /**
     * Remove and return the first element of a packed list array (no holes).
     * Returns null when the array is empty.
     */
    public function shiftFirst(): ?Variable
    {
        $this->assertConsistent();
        if (0 === $this->numElements) {
            return null;
        }
        if (!$this->isWithoutHoles()) {
            throw new \LogicException('shiftFirst() only supports packed list arrays without holes');
        }
        $this->assertSeparatedForWrite();
        $firstBucket = $this->buckets->read(0);
        $result = new Variable();
        $result->copyFrom($firstBucket->value->resolveIndirect());
        if ($this->isPackedList()) {
            for ($i = 0; $i < $this->numUsed - 1; ++$i) {
                $src = $this->buckets->read($i + 1);
                $dst = $this->buckets->read($i);
                $dst->value->copyFrom($src->value);
                $dst->hash = $i;
                $dst->key = null;
            }
            --$this->nextFreeElement;
        } else {
            for ($i = 0; $i < $this->numUsed - 1; ++$i) {
                $src = $this->buckets->read($i + 1);
                $dst = $this->buckets->read($i);
                $dst->value->copyFrom($src->value);
                $dst->hash = $src->hash;
                $dst->key = $src->key;
            }
            $this->recalcNextFreeElementFromBuckets();
        }
        --$this->numUsed;
        --$this->numElements;
        $this->rehash();

        return $result;
    }

    /**
     * Prepend one or more values to a packed list array (no holes).
     * Returns the new element count.
     */
    public function unshiftPrepend(Variable ...$values): int
    {
        $this->assertConsistent();
        $k = \count($values);
        if (0 === $k) {
            return $this->numElements;
        }
        if (!$this->isWithoutHoles()) {
            throw new \LogicException('unshiftPrepend() only supports packed list arrays without holes');
        }
        $this->assertSeparatedForWrite();
        if (0 === $this->numElements) {
            foreach ($values as $value) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $this->append($copy);
            }

            return $this->numElements;
        }
        while ($this->numUsed + $k > $this->indexes->size()) {
            $this->resize();
        }
        if ($this->isPackedList()) {
            for ($i = $this->numUsed - 1; $i >= 0; --$i) {
                $src = $this->buckets->read($i);
                $dst = $this->buckets->read($i + $k);
                $dst->value->copyFrom($src->value);
                $dst->hash = $i + $k;
                $dst->key = null;
            }
            for ($j = 0; $j < $k; ++$j) {
                $bucket = $this->buckets->read($j);
                $bucket->value->copyFrom($values[$j]);
                $bucket->hash = $j;
                $bucket->key = null;
            }
            $this->nextFreeElement += $k;
        } else {
            for ($i = $this->numUsed - 1; $i >= 0; --$i) {
                $src = $this->buckets->read($i);
                $dst = $this->buckets->read($i + $k);
                $dst->value->copyFrom($src->value);
                $dst->hash = $src->hash;
                $dst->key = $src->key;
            }
            for ($j = 0; $j < $k; ++$j) {
                $bucket = $this->buckets->read($j);
                $bucket->value->copyFrom($values[$j]);
                $bucket->hash = $j;
                $bucket->key = null;
            }
            $this->recalcNextFreeElementFromBuckets();
        }
        $this->numUsed += $k;
        $this->numElements += $k;
        $this->rehash();

        return $this->numElements;
    }

    /**
     * Replace packed list values in place (indices 0..n-1, no holes).
     *
     * @param list<Variable> $values
     */
    public function replacePackedValues(array $values): void
    {
        $this->assertConsistent();
        if (!$this->isWithoutHoles()) {
            throw new \LogicException('replacePackedValues() only supports packed list arrays without holes');
        }
        if (\count($values) !== $this->numElements) {
            throw new \LogicException('replacePackedValues() value count must match array length');
        }
        $this->assertSeparatedForWrite();
        for ($i = 0; $i < $this->numUsed; ++$i) {
            $bucket = $this->buckets->read($i);
            if ($bucket->value->isUndefined()) {
                continue;
            }
            $bucket->value->copyFrom($values[$i]);
            $bucket->hash = $i;
            $bucket->key = null;
        }
        $this->rehash();
    }

    /**
     * Copy all defined values into a new packed list array.
     */
    public function valuesCopy(): HashTable
    {
        $out = new self();
        foreach ($this->iterate(true) as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $out->append($copy);
        }

        return $out;
    }

    /**
     * Copy all keys into a new packed list array (int or string keys).
     */
    public function keysCopy(): HashTable
    {
        $out = new self();
        for ($i = 0; $i < $this->numUsed; ++$i) {
            $bucket = $this->buckets->read($i);
            if ($bucket->value->isUndefined()) {
                continue;
            }
            $keyVar = new Variable();
            if (null !== $bucket->key) {
                $keyVar->string($bucket->key);
            } else {
                $keyVar->int($bucket->hash);
            }
            $out->append($keyVar);
        }

        return $out;
    }

    /**
     * Keys whose values match $searchValue (ext/standard/array.c php_array_keys, #4266).
     */
    public function keysMatchingCopy(Variable $searchValue, bool $strict): HashTable
    {
        $out = new self();
        $searchValue = $searchValue->resolveIndirect();
        foreach ($this->iterateKeyed(true) as [$key, $value]) {
            $stored = $value->resolveIndirect();
            if ($strict ? $searchValue->identicalTo($stored) : $searchValue->equals($stored)) {
                $keyCopy = new Variable();
                $keyCopy->copyFrom($key);
                $out->append($keyCopy);
            }
        }

        return $out;
    }

    /**
     * Append values from packed list arrays into a copy of this array.
     *
     * @param HashTable ...$others
     */
    public function mergeCopy(HashTable ...$others): HashTable
    {
        $out = $this->valuesCopy();
        foreach ($others as $other) {
            if (!$other->isWithoutHoles()) {
                throw new \LogicException('mergeCopy() only supports packed list arrays without holes');
            }
            foreach ($other->iterate(true) as $value) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $out->append($copy);
            }
        }

        return $out;
    }

    /**
     * array_replace(): copy this array, then overlay keys from each replacement array.
     *
     * @param HashTable ...$others
     */
    public function replaceCopy(HashTable ...$others): HashTable
    {
        $out = new self();
        foreach ($this->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $copy);
            } else {
                $out->add($key->toString(), $copy);
            }
        }
        foreach ($others as $other) {
            foreach ($other->iterateKeyed(true) as [$key, $value]) {
                $copy = new Variable();
                $copy->copyFrom($value);
                if (Variable::TYPE_INTEGER === $key->type) {
                    $idx = $key->toInt();
                    $existing = $out->findIndex($idx);
                    if (null !== $existing) {
                        $existing->copyFrom($copy);
                    } else {
                        $out->addIndex($idx, $copy);
                    }
                } else {
                    $k = $key->toString();
                    $existing = $out->find($k);
                    if (null !== $existing) {
                        $existing->copyFrom($copy);
                    } else {
                        $out->add($k, $copy);
                    }
                }
            }
        }

        return $out;
    }

    /**
     * array_replace_key(): copy this array, then replace values only for keys that already exist.
     *
     * php-src: ext/standard/array.c — PHP_FUNCTION(array_replace_key) (PHP 8.4+; issue #5650).
     */
    public function replaceKeyCopy(HashTable $replacements): HashTable
    {
        $out = new self();
        foreach ($this->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $copy);
            } else {
                $out->add($key->toString(), $copy);
            }
        }
        foreach ($replacements->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $idx = $key->toInt();
                $existing = $out->findIndex($idx);
                if (null !== $existing) {
                    $existing->copyFrom($copy);
                }
            } else {
                $k = $key->toString();
                $existing = $out->find($k);
                if (null !== $existing) {
                    $existing->copyFrom($copy);
                }
            }
        }

        return $out;
    }

    /**
     * array_replace_recursive(): copy this array, then overlay keys from each replacement array.
     * When both the destination and replacement values for a key are arrays, merge recursively.
     *
     * @param HashTable ...$others
     */
    public function replaceRecursiveCopy(HashTable ...$others): HashTable
    {
        $out = new self();
        foreach ($this->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $copy);
            } else {
                $out->add($key->toString(), $copy);
            }
        }
        foreach ($others as $other) {
            self::replaceRecursiveOverlay($out, $other);
        }

        return $out;
    }

    private static function replaceRecursiveOverlay(HashTable $out, HashTable $other): void
    {
        foreach ($other->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $idx = $key->toInt();
                $existing = $out->findIndex($idx);
                if (null !== $existing) {
                    $existing = $existing->resolveIndirect();
                    $overlay = $copy->resolveIndirect();
                    if (Variable::TYPE_ARRAY === $existing->type && Variable::TYPE_ARRAY === $overlay->type) {
                        $existing->array($existing->toArray()->replaceRecursiveCopy($overlay->toArray()));
                    } else {
                        $slot = $out->findIndex($idx);
                        if (null !== $slot) {
                            $slot->copyFrom($copy);
                        }
                    }
                } else {
                    $out->addIndex($idx, $copy);
                }
            } else {
                $k = $key->toString();
                $existing = $out->find($k);
                if (null !== $existing) {
                    $existing = $existing->resolveIndirect();
                    $overlay = $copy->resolveIndirect();
                    if (Variable::TYPE_ARRAY === $existing->type && Variable::TYPE_ARRAY === $overlay->type) {
                        $existing->array($existing->toArray()->replaceRecursiveCopy($overlay->toArray()));
                    } else {
                        $slot = $out->find($k);
                        if (null !== $slot) {
                            $slot->copyFrom($copy);
                        }
                    }
                } else {
                    $out->add($k, $copy);
                }
            }
        }
    }

    /**
     * array_merge_recursive(): copy this array, then merge each source recursively.
     *
     * php-src: ext/standard/array.c — php_array_merge_recursive()
     *
     * @param HashTable ...$others
     */
    public function mergeRecursiveCopy(HashTable ...$others): HashTable
    {
        $out = new self();
        foreach ($this->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->duplicateFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $copy);
            } else {
                $out->add($key->toString(), $copy);
            }
        }
        foreach ($others as $other) {
            self::mergeRecursiveOverlay($out, $other);
        }

        return $out;
    }

    private static function mergeRecursiveOverlay(HashTable $dest, HashTable $src): void
    {
        foreach ($src->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->duplicateFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $dest->append($copy);
            } else {
                $k = $key->toString();
                $existing = $dest->find($k);
                if (null === $existing) {
                    $dest->add($k, $copy);
                } else {
                    $existing = $existing->resolveIndirect();
                    $overlay = $copy->resolveIndirect();
                    if (Variable::TYPE_ARRAY === $existing->type && Variable::TYPE_ARRAY === $overlay->type) {
                        $merged = $existing->toArray()->mergeRecursiveCopy($overlay->toArray());
                        $slot = $dest->find($k);
                        if (null !== $slot) {
                            $slot->array($merged);
                        }
                    } else {
                        $combined = self::mergeRecursiveCombineValues($existing, $overlay);
                        $slot = $dest->find($k);
                        if (null !== $slot) {
                            $slot->copyFrom($combined);
                        }
                    }
                }
            }
        }
    }

    private static function mergeRecursiveCombineValues(Variable $existing, Variable $overlay): Variable
    {
        $existing = $existing->resolveIndirect();
        $overlay = $overlay->resolveIndirect();
        if (Variable::TYPE_ARRAY === $existing->type && Variable::TYPE_ARRAY === $overlay->type) {
            $merged = $existing->toArray()->mergeRecursiveCopy($overlay->toArray());
            $out = new Variable();
            $out->array($merged);

            return $out;
        }
        $out = new Variable();
        $out->array(new self());
        $ht = $out->toArray();
        if (Variable::TYPE_ARRAY === $existing->type) {
            foreach ($existing->toArray()->iterateKeyed(true) as [$key, $element]) {
                $elementCopy = new Variable();
                $elementCopy->copyFrom($element);
                if (Variable::TYPE_INTEGER === $key->type) {
                    $ht->addIndex($key->toInt(), $elementCopy);
                } else {
                    $ht->add($key->toString(), $elementCopy);
                }
            }
            $elementCopy = new Variable();
            $elementCopy->copyFrom($overlay);
            $ht->append($elementCopy);
        } elseif (Variable::TYPE_ARRAY === $overlay->type) {
            $base = new self();
            $scalarCopy = new Variable();
            $scalarCopy->copyFrom($existing);
            $base->addIndex(0, $scalarCopy);
            $merged = $base->mergeRecursiveCopy($overlay->toArray());
            $out = new Variable();
            $out->array($merged);

            return $out;
        } else {
            $elementCopy = new Variable();
            $elementCopy->copyFrom($existing);
            $ht->append($elementCopy);
            $elementCopy = new Variable();
            $elementCopy->copyFrom($overlay);
            $ht->append($elementCopy);
        }

        return $out;
    }

    /**
     * Array union ($left + $right): copy this array, then append keys from $other that are missing.
     * Left-hand keys win on collision (Zend zend_hash_merge / add_function parity, issue #3690).
     */
    public function unionCopy(HashTable $other): HashTable
    {
        $out = new self();
        foreach ($this->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $out->addIndex($key->toInt(), $copy);
            } else {
                $out->add($key->toString(), $copy);
            }
        }
        $out->unionInPlace($other);

        return $out;
    }

    /**
     * In-place array union ($left += $right): add keys from $other that are missing in this table.
     */
    public function unionInPlace(HashTable $other): void
    {
        $this->assertConsistent();
        $this->assertSeparatedForWrite();
        foreach ($other->iterateKeyed(true) as [$key, $value]) {
            EnumCaseSupport::rejectIllegalArrayOffset($key);
            if (Variable::TYPE_INTEGER === $key->type) {
                if (null !== $this->findIndex($key->toInt())) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $this->addIndex($key->toInt(), $copy);
            } else {
                $k = $key->toString();
                if (null !== $this->find($k)) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $this->add($k, $copy);
            }
        }
    }

    /**
     * Copy string-keyed entries from another array into this one.
     */
    public function mergeStringKeysFrom(HashTable $other, bool $overwrite = false): void
    {
        for ($i = 0; $i < $other->numUsed; ++$i) {
            $bucket = $other->buckets->read($i);
            if ($bucket->value->isUndefined() || null === $bucket->key) {
                continue;
            }
            $existing = $this->find($bucket->key);
            if (null !== $existing) {
                if (!$overwrite) {
                    continue;
                }
                $existing->copyFrom($bucket->value->resolveIndirect());

                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($bucket->value->resolveIndirect());
            $this->add($bucket->key, $copy);
        }
    }

    /**
     * Copy values in reverse order (ext/standard/array.c php_array_reverse).
     *
     * @param bool $preserveKeys when true, int keys are kept; string keys are always preserved
     */
    public function reverseCopy(bool $preserveKeys = false): HashTable
    {
        if (!$preserveKeys && $this->isPackedList()) {
            return $this->reversePackedCopy();
        }

        $pairs = iterator_to_array($this->iterateKeyed(true), false);
        $out = new self();
        for ($i = \count($pairs) - 1; $i >= 0; --$i) {
            [$key, $value] = $this->duplicateKeyedPair($pairs[$i]);
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_STRING === $key->type) {
                $out->add($key->toString(), $copy);
            } elseif ($preserveKeys) {
                $out->addIndex($key->toInt(), $copy);
            } else {
                $out->append($copy);
            }
        }

        return $out;
    }

    /**
     * Reverse a packed list and re-index from zero (array_reverse $preserve_keys=false fast path).
     */
    private function reversePackedCopy(): HashTable
    {
        if (!$this->isWithoutHoles()) {
            throw new \LogicException('reverseCopy() only supports packed list arrays without holes');
        }
        $values = [];
        foreach ($this->iterate(true) as $value) {
            $values[] = $value;
        }
        $out = new self();
        for ($i = \count($values) - 1; $i >= 0; --$i) {
            $copy = new Variable();
            $copy->copyFrom($values[$i]);
            $out->append($copy);
        }

        return $out;
    }

    /**
     * Pad a packed list to {@param $length} elements with {@param $value} (array_pad subset).
     */
    public function padCopy(int $length, Variable $value): HashTable
    {
        if (!$this->isWithoutHoles()) {
            throw new \LogicException('padCopy() only supports packed list arrays without holes');
        }
        $count = $this->numElements;
        $target = abs($length);
        if ($target <= $count) {
            return $this->copyAllKeyedEntries();
        }
        $padCount = $target - $count;
        $pad = new Variable();
        $pad->copyFrom($value);
        if ($length > 0) {
            $out = $this->copyAllKeyedEntries();
            for ($i = 0; $i < $padCount; ++$i) {
                $copy = new Variable();
                $copy->copyFrom($pad);
                $out->append($copy);
            }

            return $out;
        }
        $prepend = [];
        for ($i = 0; $i < $padCount; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($pad);
            $prepend[] = $copy;
        }
        $out = new self();
        $out->unshiftPrepend(...$prepend);
        foreach ($this->iterate(true) as $element) {
            $copy = new Variable();
            $copy->copyFrom($element);
            $out->append($copy);
        }

        return $out;
    }

    /** Shallow copy preserving string/int keys (array_pad subset, #10777). */
    private function copyAllKeyedEntries(): self
    {
        $out = new self();
        foreach ($this->iterateKeyed(true) as [$key, $element]) {
            $this->copyKeyedEntry($out, $key, $element);
        }

        return $out;
    }

    /**
     * List spread tail for keyed destructuring: all entries except excluded string keys and int indices below $offset (#4889).
     *
     * @param list<string> $excludedStringKeys
     */
    public function copyListSpreadTail(int $offset, array $excludedStringKeys): self
    {
        $exclude = array_flip($excludedStringKeys);
        $out = new self();
        foreach ($this->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_INTEGER === $key->type) {
                if ($key->toInt() < $offset) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $out->addIndex($key->toInt(), $copy);
            } else {
                $k = $key->toString();
                if (isset($exclude[$k])) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $out->add($k, $copy);
            }
        }

        return $out;
    }

    /**
     * Copy a sub-range of an array (ext/standard/array.c php_array_slice).
     *
     * @param bool $preserveKeys when true, keep int/string keys; otherwise reindex packed lists
     */
    public function sliceCopy(int $offset, ?int $length = null, bool $preserveKeys = false): HashTable
    {
        if ($preserveKeys) {
            return $this->sliceCopyPreserveKeys($offset, $length);
        }
        if ($this->isPackedList()) {
            [$offset, $takeLen] = $this->normalizeSpliceRange($offset, $length, $this->numElements);
            $out = new self();
            $values = iterator_to_array($this->iterate(true), false);
            for ($i = $offset; $i < $offset + $takeLen; ++$i) {
                if (!isset($values[$i])) {
                    break;
                }
                $copy = new Variable();
                $copy->copyFrom($values[$i]);
                $out->append($copy);
            }

            return $out;
        }

        return $this->sliceCopyReindexIntKeys($offset, $length);
    }

    /**
     * array_slice() with preserve_keys=false on mixed/assoc arrays (ext/standard/array.c #10600).
     *
     * String keys are kept; integer keys are renumbered from 0.
     */
    private function sliceCopyReindexIntKeys(int $offset, ?int $length = null): HashTable
    {
        $pairs = iterator_to_array($this->iterateKeyed(true), false);
        $num = \count($pairs);
        [$offset, $takeLen] = $this->normalizeSpliceRange($offset, $length, $num);

        $out = new self();
        $nextIntKey = 0;
        for ($i = $offset; $i < $offset + $takeLen; ++$i) {
            [$key, $value] = $this->duplicateKeyedPair($pairs[$i]);
            if (Variable::TYPE_STRING === $key->type) {
                $this->copyKeyedEntry($out, $key, $value);
                continue;
            }
            $reindexed = new Variable(Variable::TYPE_INTEGER);
            $reindexed->int($nextIntKey);
            ++$nextIntKey;
            $this->copyKeyedEntry($out, $reindexed, $value);
        }

        return $out;
    }

    private function sliceCopyPreserveKeys(int $offset, ?int $length = null): HashTable
    {
        $pairs = iterator_to_array($this->iterateKeyed(true), false);
        $num = \count($pairs);
        [$offset, $takeLen] = $this->normalizeSpliceRange($offset, $length, $num);

        $out = new self();
        for ($i = $offset; $i < $offset + $takeLen; ++$i) {
            [$key, $value] = $this->duplicateKeyedPair($pairs[$i]);
            $this->copyKeyedEntry($out, $key, $value);
        }

        return $out;
    }

    /**
     * Whether this array is a packed list (0..n-1 int keys, no string keys).
     */
    public function isPackedList(): bool
    {
        if (0 === $this->numElements) {
            return true;
        }
        if (!$this->isWithoutHoles()) {
            return false;
        }
        $pos = 0;
        for ($i = 0; $i < $this->numUsed; ++$i) {
            $bucket = $this->buckets->read($i);
            if ($bucket->value->isUndefined()) {
                continue;
            }
            if (null !== $bucket->key) {
                return false;
            }
            if ($bucket->hash !== $pos) {
                return false;
            }
            ++$pos;
        }

        return $pos === $this->numElements;
    }

    /**
     * Remove a portion of an array, optionally replace it, and return the removed slice.
     *
     * Packed lists renumber; associative arrays preserve keys (ext/standard/array.c).
     */
    public function spliceInPlace(int $offset, ?int $length = null, ?HashTable $replacement = null): HashTable
    {
        $this->assertConsistent();
        $this->assertSeparatedForWrite();
        if ($this->isPackedList()) {
            return $this->splicePackedInPlace($offset, $length, $replacement);
        }

        return $this->spliceKeyedInPlace($offset, $length, $replacement);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function normalizeSpliceRange(int $offset, ?int $length, int $num): array
    {
        if ($offset < 0) {
            $offset = $num + $offset;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        if (null === $length) {
            $removeLen = $num - $offset;
        } elseif ($length < 0) {
            $removeLen = $num - $offset + $length;
        } else {
            $removeLen = $length;
        }
        if ($removeLen < 0) {
            $removeLen = 0;
        }
        if ($offset >= $num) {
            $removeLen = 0;
        } elseif ($removeLen > $num - $offset) {
            $removeLen = $num - $offset;
        }

        return [$offset, $removeLen];
    }

    private function splicePackedInPlace(int $offset, ?int $length, ?HashTable $replacement): HashTable
    {
        [$offset, $removeLen] = $this->normalizeSpliceRange($offset, $length, $this->numElements);
        $removed = $this->sliceCopy($offset, $removeLen);

        $values = [];
        foreach ($this->iterate(true) as $value) {
            $values[] = $value;
        }

        $num = $this->numElements;
        $newValues = [];
        for ($i = 0; $i < $offset; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($values[$i]);
            $newValues[] = $copy;
        }
        if (null !== $replacement) {
            foreach ($replacement->iterate(true) as $value) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $newValues[] = $copy;
            }
        }
        for ($i = $offset + $removeLen; $i < $num; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($values[$i]);
            $newValues[] = $copy;
        }

        $this->assignPackedList($newValues);

        return $removed;
    }

    private function spliceKeyedInPlace(int $offset, ?int $length, ?HashTable $replacement): HashTable
    {
        $pairs = iterator_to_array($this->iterateKeyed(true), false);
        $num = \count($pairs);
        [$offset, $removeLen] = $this->normalizeSpliceRange($offset, $length, $num);

        $removed = new self();
        for ($i = $offset; $i < $offset + $removeLen; ++$i) {
            [$key, $value] = $this->duplicateKeyedPair($pairs[$i]);
            $this->copyKeyedEntry($removed, $key, $value);
        }

        $newPairs = [];
        for ($i = 0; $i < $offset; ++$i) {
            $newPairs[] = $this->duplicateKeyedPair($pairs[$i]);
        }
        $replacementCount = null !== $replacement ? $replacement->getNumElements() : 0;
        $this->appendSpliceReplacement($newPairs, $replacement, $offset, false);
        $nextIntKey = $replacementCount;
        for ($i = $offset + $removeLen; $i < $num; ++$i) {
            $pair = $this->duplicateKeyedPair($pairs[$i]);
            if ($replacementCount > 0 && Variable::TYPE_INTEGER === $pair[0]->type) {
                $pair[0]->int($nextIntKey++);
            }
            $newPairs[] = $pair;
        }

        $this->assignFromKeyedPairs($newPairs);

        return $removed;
    }

    /**
     * Insert replacement values with Zend array_splice key rules (ext/standard/array.c).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    private function appendSpliceReplacement(array &$pairs, ?HashTable $replacement, int $offset, bool $destIsPacked): void
    {
        if (null === $replacement) {
            return;
        }
        $i = 0;
        foreach ($replacement->iterate(true) as $value) {
            $key = new Variable();
            $key->int($destIsPacked ? $offset + $i : $i);
            $copy = new Variable();
            $copy->copyFrom($value);
            $pairs[] = [$key, $copy];
            ++$i;
        }
    }

    /**
     * @param array{0: Variable, 1: Variable} $pair
     *
     * @return array{0: Variable, 1: Variable}
     */
    private function duplicateKeyedPair(array $pair): array
    {
        [$key, $value] = $pair;
        $keyCopy = new Variable();
        if (Variable::TYPE_INTEGER === $key->type) {
            $keyCopy->int($key->toInt());
        } else {
            $keyCopy->string($key->toString());
        }
        $valCopy = new Variable();
        $valCopy->copyFrom($value->resolveIndirect());

        return [$keyCopy, $valCopy];
    }

    private function copyKeyedEntry(self $dest, Variable $key, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value);
        if (Variable::TYPE_INTEGER === $key->type) {
            $dest->addIndex($key->toInt(), $copy);
        } else {
            $dest->add($key->toString(), $copy);
        }
    }

    /**
     * Replace an associative array in key order (array_multisort single-array path; #10653).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public function reorderKeyedPairs(array $pairs): void
    {
        $this->assignFromKeyedPairs($pairs);
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    private function assignFromKeyedPairs(array $pairs): void
    {
        $this->assertConsistent();
        $this->assertSeparatedForWrite();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            $this->initMixed();
        }
        for ($i = 0; $i < $this->numUsed; ++$i) {
            $bucket = $this->buckets->read($i);
            if (!$bucket->value->isUndefined()) {
                $bucket->value->reset();
                $bucket->value->type = Variable::TYPE_UNDEFINED;
            }
        }
        $this->numUsed = 0;
        $this->numElements = 0;
        $this->nextFreeElement = 0;
        $this->rehash();
        foreach ($pairs as [$key, $value]) {
            $this->copyKeyedEntry($this, $key, $value);
        }
    }

    /**
     * Replace a packed list array's contents with a new ordered value list.
     *
     * @param list<Variable> $values
     */
    public function assignPackedList(array $values): void
    {
        $this->assertConsistent();
        if ($this->numElements > 0 && !$this->isWithoutHoles()) {
            throw new \LogicException('assignPackedList() only supports packed list arrays without holes');
        }
        $this->assertSeparatedForWrite();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            $this->initMixed();
        }

        $n = \count($values);
        while ($n > $this->indexes->size()) {
            $this->resize();
        }
        while ($this->numUsed < $n) {
            $this->resizeIfFull();
            ++$this->numUsed;
        }
        for ($i = 0; $i < $n; ++$i) {
            $bucket = $this->buckets->read($i);
            $bucket->value->copyFrom($values[$i]);
            $bucket->hash = $i;
            $bucket->key = null;
        }
        $this->numUsed = $n;
        $this->numElements = $n;
        $this->nextFreeElement = $n;
        $this->rehash();
    }

    /**
     * Split an array into consecutive chunks (array_chunk; ext/standard/array.c preserve_keys branch).
     */
    public function chunkCopy(int $size, bool $preserveKeys = false): HashTable
    {
        if ($size <= 0) {
            throw new \ValueError('array_chunk(): Argument #2 ($length) must be greater than 0');
        }
        if ($preserveKeys) {
            return $this->chunkCopyPreserveKeys($size);
        }
        if (!$this->isWithoutHoles()) {
            throw new \LogicException('array_chunk() only supports packed list arrays without holes');
        }
        $out = new self();
        $chunk = null;
        $count = 0;
        foreach ($this->iterate(true) as $value) {
            if (0 === $count) {
                $chunk = new self();
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $chunk->append($copy);
            ++$count;
            if ($count >= $size) {
                $wrapper = new Variable();
                $wrapper->array($chunk);
                $out->append($wrapper);
                $chunk = null;
                $count = 0;
            }
        }
        if (null !== $chunk && $count > 0) {
            $wrapper = new Variable();
            $wrapper->array($chunk);
            $out->append($wrapper);
        }

        return $out;
    }

    private function chunkCopyPreserveKeys(int $size): HashTable
    {
        $out = new self();
        $chunk = null;
        $count = 0;
        foreach ($this->iterateKeyed(true) as [$key, $value]) {
            if (Variable::TYPE_INTEGER !== $key->type && Variable::TYPE_STRING !== $key->type) {
                throw new \TypeError('array_chunk(): Argument #1 ($array) must contain only int and string keys');
            }
            if (0 === $count) {
                $chunk = new self();
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            if (Variable::TYPE_INTEGER === $key->type) {
                $chunk->addIndex($key->toInt(), $copy);
            } else {
                $chunk->add($key->toString(), $copy);
            }
            ++$count;
            if ($count >= $size) {
                $wrapper = new Variable();
                $wrapper->array($chunk);
                $out->append($wrapper);
                $chunk = null;
                $count = 0;
            }
        }
        if (null !== $chunk && $count > 0) {
            $wrapper = new Variable();
            $wrapper->array($chunk);
            $out->append($wrapper);
        }

        return $out;
    }

    public function hasKey(Variable $index): bool
    {
        $this->assertConsistent();
        $index = self::normalizeIndexKey($index);
        switch ($index->type) {
            case Variable::TYPE_INTEGER:
                $value = $this->findIndex($index->toInt());
                break;
            case Variable::TYPE_FLOAT:
                $value = $this->findIndex($index->toInt());
                break;
            case Variable::TYPE_STRING:
                $value = $this->findByStringKey($index->toString());
                break;
            default:
                return false;
        }

        return null !== $value && !$value->isUndefined();
    }

    /**
     * Whether an offset exists and is not null (PHP isset() on arrays).
     */
    public function offsetIsSet(Variable $index): bool
    {
        $index = self::normalizeIndexKey($index, 'Illegal offset type in isset or empty');
        $stored = null;
        switch ($index->type) {
            case Variable::TYPE_INTEGER:
                $stored = $this->findIndex($index->toInt());
                break;
            case Variable::TYPE_FLOAT:
                $stored = $this->findIndex($index->toInt());
                break;
            case Variable::TYPE_STRING:
                $stored = $this->findByStringKey($index->toString());
                break;
            default:
                throw new \LogicException("Unknown index type {$index->type}");
        }
        if (null === $stored) {
            return false;
        }
        $value = $stored->resolveIndirect();

        return !$value->isUndefined() && Variable::TYPE_NULL !== $value->type;
    }

    public function offsetUnset(Variable $index): void
    {
        $this->assertConsistent();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            return;
        }
        $this->assertSeparatedForWrite();
        $bucket = null;
        $bucketIndex = self::INVALID_INDEX;
        $index = self::normalizeIndexKey($index, 'Illegal offset type in unset');
        switch ($index->type) {
            case Variable::TYPE_INTEGER:
                $bucketIndex = $this->findBucketIndex($index->toInt(), null);
                $bucket = self::INVALID_INDEX !== $bucketIndex ? $this->buckets->read($bucketIndex) : null;
                break;
            case Variable::TYPE_FLOAT:
                $bucketIndex = $this->findBucketIndex($index->toInt(), null);
                $bucket = self::INVALID_INDEX !== $bucketIndex ? $this->buckets->read($bucketIndex) : null;
                break;
            case Variable::TYPE_STRING:
                $bucketIndex = $this->findBucketIndexByStringKey($index->toString());
                $bucket = self::INVALID_INDEX !== $bucketIndex ? $this->buckets->read($bucketIndex) : null;
                break;
            default:
                throw new \LogicException("Unknown index type {$index->type}");
        }
        if (null === $bucket) {
            return;
        }
        $value = $bucket->value->resolveIndirect();
        if ($value->isUndefined() || Variable::TYPE_NULL === $value->type) {
            return;
        }
        $bucket->value->reset();
        $bucket->value->type = Variable::TYPE_UNDEFINED;
        --$this->numElements;
        if ($this->internalPointer === $bucketIndex) {
            $this->updateInternalPointerAfterUnsetCurrent($bucketIndex);
        }
    }

    /**
     * Zend zend_hash_internal_pointer_update() after unset at nInternalPointer (Zend/zend_hash.c; #10349).
     */
    private function updateInternalPointerAfterUnsetCurrent(int $fromIndex): void
    {
        if (0 === $this->numElements) {
            $this->internalPointer = self::INVALID_INDEX;

            return;
        }
        $idx = $fromIndex;
        while ($idx < $this->numUsed) {
            if (!$this->buckets->read($idx)->value->isUndefined()) {
                $this->internalPointer = $idx;

                return;
            }
            ++$idx;
        }
        $this->internalPointer = self::INVALID_INDEX;
    }

    private function findBucketByStringKey(string $key): ?HashTableBucket
    {
        $idx = $this->findBucketIndexByStringKey($key);
        if (self::INVALID_INDEX === $idx) {
            return null;
        }

        return $this->buckets->read($idx);
    }

    private function findBucketIndexByStringKey(string $key): int
    {
        $intKey = self::tryIntFromNumericString($key);
        if (null !== $intKey) {
            $idx = $this->findBucketIndex($intKey, null);
            if (self::INVALID_INDEX !== $idx) {
                return $idx;
            }
        }

        return $this->findBucketIndex($this->hash($key), $key);
    }

    public function append(Variable $data): ?Variable {
        return $this->addOrUpdate($this->nextFreeElement, null, $data, self::ADD | self::ADD_NEXT);
    }

    /**
     * Merge one spread operand key into a literal/array-unpack destination (Zend array_merge / #5072).
     *
     * Integer keys and numeric-string keys renumber (append). Non-numeric string keys overwrite.
     */
    public static function spreadMergeKey(HashTable $dest, Variable $key, Variable $value): void
    {
        $key = $key->resolveIndirect();
        EnumCaseSupport::rejectIllegalArrayOffset($key);
        if ($key->is(Variable::TYPE_INTEGER)) {
            $dest->append($value);

            return;
        }
        $keyStr = $key->toString();
        if (null !== self::tryIntFromNumericString($keyStr)) {
            $dest->append($value);

            return;
        }
        $dest->update($keyStr, $value);
    }

    /**
     * Array-literal spread: int keys append; string keys preserve key (issue #141).
     */
    public function spreadFrom(HashTable $source): void
    {
        foreach ($source->iterateKeyed(true) as [$key, $value]) {
            $copy = new Variable();
            $copy->copyFrom($value);
            self::spreadMergeKey($this, $key, $copy);
        }
    }

    /**
     * Grow the hash index table so integer keys up to $maxHashIndex are not masked together.
     * Required for sparse int-key arrays (e.g. count_chars() mode 1; ext/standard/string.c).
     */
    public function ensureHashSlotCapacity(int $maxHashIndex): void
    {
        $this->assertConsistent();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            $this->initMixed();
        }
        while ($maxHashIndex >= $this->indexes->size()) {
            $this->resize();
        }
    }

    public function addIndex(int $index, Variable $data): ?Variable {
        return $this->addOrUpdate($index, null, $data, self::ADD);
    }

    public function addNewIndex(int $index, Variable $data): ?Variable {
        return $this->addOrUpdate($index, null, $data, self::ADD | self::ADD_NEW);
    }

    public function updateIndex(int $index, Variable $data): ?Variable {
        return $this->addOrUpdate($index, null, $data, self::UPDATE);
    }

    public function updateIndirectIndex(int $index, Variable $data): ?Variable {
        return $this->addOrUpdate($index, null, $data, self::UPDATE | self::UPDATE_INDIRECT);
    }

    public function add(string $key, Variable $data): ?Variable {
        $intKey = self::tryIntFromNumericString($key);
        if (null !== $intKey) {
            return $this->addIndex($intKey, $data);
        }

        return $this->addOrUpdate($this->hash($key), $key, $data, self::ADD);
    }

    public function addNew(string $key, Variable $data): ?Variable {
        $intKey = self::tryIntFromNumericString($key);
        if (null !== $intKey) {
            return $this->addNewIndex($intKey, $data);
        }

        return $this->addOrUpdate($this->hash($key), $key, $data, self::ADD_NEW);
    }

    public function update(string $key, Variable $data): ?Variable {
        $intKey = self::tryIntFromNumericString($key);
        if (null !== $intKey) {
            return $this->updateIndex($intKey, $data);
        }

        return $this->addOrUpdate($this->hash($key), $key, $data, self::UPDATE);
    }

    public function updateIndirect(string $key, Variable $data): ?Variable {
        $intKey = self::tryIntFromNumericString($key);
        if (null !== $intKey) {
            return $this->updateIndirectIndex($intKey, $data);
        }

        return $this->addOrUpdate($this->hash($key), $key, $data, self::UPDATE | self::UPDATE_INDIRECT);
    }

    private function addOrUpdate(int $hash, ?string $key, Variable $data, int $flags): ?Variable {
        $this->assertConsistent();
        $this->assertSeparatedForWrite();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            $this->initMixed();
        }
        $this->resizeIfFull();
        if (($flags & self::ADD_NEW) === 0) {
            $bucket = $this->findBucket($hash, $key);
            if ($bucket) {
                if ($flags & self::ADD) {
                    if (!($flags & self::UPDATE_INDIRECT)) {
                        return null;
                    }
                    $bucketData = $bucket->value;
                    if ($bucketData->isIndirect()) {
                        $bucketData = $bucketData->resolveIndirect();
                        if (!$bucketData->isUndefined()) {
                            return null;
                        }
                    } else {
                        return null;
                    }
                } else {
                    $bucketData = $bucket->value;
                    if (($flags & self::UPDATE_INDIRECT) && $bucketData->isIndirect()) {
                        $bucketData = $bucketData->resolveIndirect();
                    }
                }
                $bucketData->copyFrom($data);
                return $bucketData;
            }
        }
        $this->resizeIfFull();
        $id = $this->numUsed++;
        $this->numElements++;
        $bucket = $this->buckets->read($id);
        $bucket->key = $key;
        $bucket->hash = $hash;
        $bucket->value->next = $this->indexes->read($hash);
        $this->indexes->write($hash, $id);
        if ($data->isIndirect()) {
            $bucket->value->duplicateFrom($data);
        } else {
            $bucket->value->copyFrom($data);
        }
        if (is_null($key) && $hash >= $this->nextFreeElement) {
            $this->nextFreeElement = $hash + 1;
        }
        return $bucket->value;
    }

    private function findBucket(int $hash, ?string $key): ?HashTableBucket
    {
        $idx = $this->findBucketIndex($hash, $key);
        if (self::INVALID_INDEX === $idx) {
            return null;
        }

        return $this->buckets->read($idx);
    }

    private function findBucketIndex(int $hash, ?string $key): int
    {
        $idx = $this->indexes->read($hash);
        do {
            if ($idx === self::INVALID_INDEX) {
                return self::INVALID_INDEX;
            }
            $bucket = $this->buckets->read($idx);
            if ($bucket->key === $key && (null !== $key || $bucket->hash === $hash)) {
                return $idx;
            }
            $idx = $bucket->value->next;
        } while (true);
    }

    private function assertUninitialized(): void {
        if (0 === ($this->flags & self::FLAG_UNINITIALIZED)) {
            throw new \LogicException('Hash table was asserted to be uninitialized, but was initialized');
        }
    }

    private function assertConsistent(): void {
        if (($this->flags & self::FLAG_CONSISTENCY) === self::OKAY) {
            return;
        }
        switch ($this->flags & self::FLAG_CONSISTENCY) {
            case self::IS_DESTROYING:
                throw new \LogicException('Hash table is being destroyed');
            case self::DESTROYED:
                throw new \LogicException('Hash table is already destroyed');
            case self::CLEANING:
                throw new \LogicException('Hash table is being cleaned');
        }
        // Should never happen
        throw new \LogicException('Hash table is inconsistent');
    }

    private function init(bool $packed) {
        $this->assertSeparatedForWrite();
        $this->assertUninitialized();
        if ($packed) {
            $this->initPacked();
        } else {
            $this->initMixed();
        }
    }

    private function initMixed(): void {
        $this->flags = $this->flags & ~self::FLAG_UNINITIALIZED;
        $this->rehash();
    }

    private function resizeIfFull(): void {
        if ($this->numUsed >= $this->indexes->size()) {
            $this->resize();
        }
    }

    private function resize(): void {
        if ($this->numUsed > $this->numElements + ($this->numElements >> 5)) {
            $this->rehash();
            return;
        }
        $oldSize = $this->indexes->size();
        $this->indexes->grow(); // increase by factor of 2
        $this->buckets->grow();
        $newSize = $this->indexes->size();
        for ($i = $oldSize; $i < $newSize; $i++) {
            $this->indexes->write($i, self::INVALID_INDEX);
            $this->buckets->write($i, new HashTableBucket(new Variable(Variable::TYPE_UNDEFINED), 0, null));
        }
        $this->rehash();
    }

    private function rehash(): void {
        if ($this->numElements === 0) {
            if (!($this->flags & self::FLAG_UNINITIALIZED)) {
                $this->numUsed = 0;
                for ($i = 0, $n = $this->indexes->size(); $i < $n; $i++) {
                    $this->indexes->write($i, self::INVALID_INDEX);
                    $this->buckets->write($i, new HashTableBucket(new Variable(Variable::TYPE_UNDEFINED), 0, null));
                }
            }
            return;
        }
        $this->reset();
        $bucketIndex = 0;
        if ($this->isWithoutHoles()) {
            do {
                $bucket = $this->buckets->read($bucketIndex);
                $hash = $bucket->hash;
                $bucket->value->next = $this->indexes->read($hash);
                $this->indexes->write($hash, $bucketIndex);
            } while (++$bucketIndex < $this->numUsed);

            return;
        }
        for ($bucketIndex = 0; $bucketIndex < $this->numUsed; ++$bucketIndex) {
            $bucket = $this->buckets->read($bucketIndex);
            if ($bucket->value->isUndefined()) {
                continue;
            }
            $hash = $bucket->hash;
            $bucket->value->next = $this->indexes->read($hash);
            $this->indexes->write($hash, $bucketIndex);
        }
    }

    private function isWithoutHoles(): bool {
        return $this->numUsed === $this->numElements;
    }

    /** Recompute nextFreeElement from int-key buckets (ext/standard/array.c keyed pop/shift/unshift). */
    private function recalcNextFreeElementFromBuckets(): void
    {
        $next = 0;
        for ($i = 0; $i < $this->numUsed; ++$i) {
            $bucket = $this->buckets->read($i);
            if ($bucket->value->isUndefined()) {
                continue;
            }
            if (null === $bucket->key && $bucket->hash >= $next) {
                $next = $bucket->hash + 1;
            }
        }
        $this->nextFreeElement = $next;
    }

    private function reset() {
        for ($i = 0, $n = $this->indexes->size(); $i < $n; $i++) {
            $this->indexes->write($i, self::INVALID_INDEX);
        }
    }

    private function hash(string $key): int {
        $hash = 5381;
        for ($i = 0, $len = strlen($key); $i < $len; $i++) {
            // Keep djb2 steps in int range (PHP 8.2 deprecates float→int conversion).
            $hash = (($hash << 5) + $hash + ord($key[$i])) & 0x7FFFFFFF;
        }
        return $hash | \PHP_INT_MIN;
    }
}

final class HashTableBucket {
    public Variable $value;
    public int $hash;
    public ?string $key;

    public function __construct(Variable $value, int $hash, ?string $key) {
        $this->value = $value;
        $this->hash = $hash;
        $this->key = $key;
    }
}
