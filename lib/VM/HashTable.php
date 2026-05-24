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
        if ($bucket->value->isUndefined()) {
            throw new \LogicException('Invalid foreach value position');
        }
        if ($byRef) {
            return $bucket->value;
        }
        $result = new Variable();
        $result->copyFrom($bucket->value->resolveIndirect());

        return $result;
    }

    public function keyExists(Variable $index): bool
    {
        switch ($index->type) {
            case Variable::TYPE_INTEGER:
                return null !== $this->findIndex($index->toInt());
            case Variable::TYPE_STRING:
                return null !== $this->find($index->toString());
            default:
                throw new \LogicException("Unknown index type {$index->type}");
        }
    }

    public function findVariable(Variable $index, bool $forWrite): ?Variable {
        switch ($index->type) {
            case Variable::TYPE_INTEGER:
                $result = $this->findIndex($index->toInt());
                break;
            case Variable::TYPE_STRING:
                $result = $this->find($index->toString());
                break;
            default:
                throw new \LogicException("Unknown index type {$index->type}");
        }
        if (is_null($result)) {
            $result = new Variable;
            if ($forWrite) {
                if ($index->type === Variable::TYPE_INTEGER) {
                    return $this->addIndex($index->toInt(), $result);
                } else {
                    return $this->add($index->toString(), $result);
                }
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
        $this->assertConsistent();
        if ($this->flags & self::FLAG_UNINITIALIZED) {
            return null;
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
        $this->refcount->assertSeparated();
        $lastSlot = $this->numUsed - 1;
        $bucket = $this->buckets->read($lastSlot);
        $result = new Variable();
        $result->copyFrom($bucket->value->resolveIndirect());
        --$this->numUsed;
        --$this->numElements;
        --$this->nextFreeElement;
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
        $this->refcount->assertSeparated();
        $firstBucket = $this->buckets->read(0);
        $result = new Variable();
        $result->copyFrom($firstBucket->value->resolveIndirect());
        for ($i = 0; $i < $this->numUsed - 1; ++$i) {
            $src = $this->buckets->read($i + 1);
            $dst = $this->buckets->read($i);
            $dst->value->copyFrom($src->value);
            $dst->hash = $i;
            $dst->key = null;
        }
        --$this->numUsed;
        --$this->numElements;
        --$this->nextFreeElement;
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
        $this->refcount->assertSeparated();
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
        $this->numUsed += $k;
        $this->numElements += $k;
        $this->nextFreeElement += $k;
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
        $this->refcount->assertSeparated();
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
     * Copy values in reverse order into a new packed list array.
     */
    public function reverseCopy(): HashTable
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
     * Copy a sub-range of a packed list array into a new list (non-negative offset).
     */
    public function sliceCopy(int $offset, ?int $length = null): HashTable
    {
        if (!$this->isWithoutHoles()) {
            throw new \LogicException('sliceCopy() only supports packed list arrays without holes');
        }
        if ($offset < 0) {
            $offset = $this->numElements + $offset;
            if ($offset < 0) {
                $offset = 0;
            }
        }
        $out = new self();
        $index = 0;
        $taken = 0;
        foreach ($this->iterate(true) as $value) {
            if ($index < $offset) {
                ++$index;
                continue;
            }
            if (null !== $length && $taken >= $length) {
                break;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $out->append($copy);
            ++$index;
            ++$taken;
        }

        return $out;
    }

    /**
     * Remove a portion of a packed list array, optionally replace it, and return the removed slice.
     *
     * @param list<Variable> $replacement
     */
    public function spliceInPlace(int $offset, ?int $length = null, array $replacement = []): HashTable
    {
        $this->assertConsistent();
        if (!$this->isWithoutHoles()) {
            throw new \LogicException('spliceInPlace() only supports packed list arrays without holes');
        }
        $this->refcount->assertSeparated();

        $num = $this->numElements;
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

        $removed = $this->sliceCopy($offset, $removeLen);

        $values = [];
        foreach ($this->iterate(true) as $value) {
            $values[] = $value;
        }

        $newValues = [];
        for ($i = 0; $i < $offset; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($values[$i]);
            $newValues[] = $copy;
        }
        foreach ($replacement as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $newValues[] = $copy;
        }
        for ($i = $offset + $removeLen; $i < $num; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($values[$i]);
            $newValues[] = $copy;
        }

        $this->assignPackedList($newValues);

        return $removed;
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
        $this->refcount->assertSeparated();
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
     * Split a packed list array into consecutive chunks (preserve_keys=false subset).
     */
    public function chunkCopy(int $size, bool $preserveKeys = false): HashTable
    {
        if ($size <= 0) {
            throw new \LogicException('array_chunk() size must be greater than zero');
        }
        if ($preserveKeys) {
            throw new \LogicException('array_chunk() preserve_keys=true is not supported in this compiler build');
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

    public function hasKey(Variable $index): bool
    {
        $this->assertConsistent();
        switch ($index->type) {
            case Variable::TYPE_INTEGER:
                $value = $this->findIndex($index->toInt());
                break;
            case Variable::TYPE_STRING:
                $value = $this->find($index->toString());
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
        $stored = null;
        switch ($index->type) {
            case Variable::TYPE_INTEGER:
                $stored = $this->findIndex($index->toInt());
                break;
            case Variable::TYPE_STRING:
                $stored = $this->find($index->toString());
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

    public function append(Variable $data): ?Variable {
        return $this->addOrUpdate($this->nextFreeElement, null, $data, self::ADD | self::ADD_NEXT);
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
        return $this->addOrUpdate($this->hash($key), $key, $data, self::ADD);
    }

    public function addNew(string $key, Variable $data): ?Variable {
        return $this->addOrUpdate($this->hash($key), $key, $data, self::ADD_NEW);
    }

    public function update(string $key, Variable $data): ?Variable {
        return $this->addOrUpdate($this->hash($key), $key, $data, self::UPDATE);
    }

    public function updateIndirect(string $key, Variable $data): ?Variable {
        return $this->addOrUpdate($this->hash($key), $key, $data, self::UPDATE | self::UPDATE_INDIRECT);
    }

    private function addOrUpdate(int $hash, ?string $key, Variable $data, int $flags): ?Variable {
        $this->assertConsistent();
        $this->refcount->assertSeparated();
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
        $bucket->value->copyFrom($data);
        if (is_null($key) && $hash >= $this->nextFreeElement) {
            $this->nextFreeElement = $hash + 1;
        }
        return $bucket->value;
    }

    private function findBucket(int $hash, ?string $key): ?HashTableBucket {
        $idx = $this->indexes->read($hash);
        do {
            if ($idx === self::INVALID_INDEX) {
                return null;
            }
            $bucket = $this->buckets->read($idx);
            if ($bucket->key === $key) {
                return $bucket;
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
        $this->refcount->assertSeparated();
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
        //todo
        throw new \LogicException('Need to implement rehash');
    }

    private function isWithoutHoles(): bool {
        return $this->numUsed === $this->numElements;
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
