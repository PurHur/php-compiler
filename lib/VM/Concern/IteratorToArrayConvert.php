<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\ext\standard\VmIteratorWalk;
use PHPCompiler\VM\ForeachIterator;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\IterableCheck;
use PHPCompiler\VM\Variable;

/**
 * Materialize Traversable / Generator / ArrayObject into a HashTable for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code iteratorToArray} through
 * {@code appendHashTableEntry} (php-src ext/standard/array.c {@code iterator_to_array};
 * Zend/zend_iterators.c / SPL ArrayObject getArrayCopy — #3100, #4244, #23573, #23713).
 * Concern trait — same namespace as parent so relative Frame / Block helpers resolve.
 * Move-only; no new C ABI. {@code appendHashTableEntry} stays reachable from
 * {@see UserInvokeArrayAccessAndClosureCall} via the composed VM class.
 */
trait IteratorToArrayConvert
{
    /**
     * Materialize a Traversable (array, Generator, or Iterator) into a new array (ext/spl iterator_to_array parity, #3100, #4244).
     */
    public function iteratorToArray(Variable $iterator, bool $preserveKeys = false, ?Frame $frame = null): HashTable
    {
        $iterator = VmIteratorWalk::assertTraversable(
            $iterator,
            $this->context,
            'iterator_to_array',
            'iterator'
        );
        $iterator = $iterator->resolveIndirect();
        $out = new HashTable();
        if (Variable::TYPE_ARRAY === $iterator->type) {
            $index = 0;
            foreach ($iterator->toArray()->iterateKeyed(true) as [$key, $value]) {
                if ($preserveKeys) {
                    self::appendHashTableEntry($out, $key, $value);
                } else {
                    $packedKey = new Variable();
                    $packedKey->int($index++);
                    self::appendHashTableEntry($out, $packedKey, $value);
                }
            }

            return $out;
        }
        if ($this->variableIsGenerator($iterator)) {
            $gen = $iterator->toObject()->generatorState;
            VmIteratorWalk::assertGeneratorIterableForRewind($gen);
            $gen->rewind();
            $index = 0;
            // After rewind the generator is on the opening yield — collect before advance (#23713).
            while ($gen->hasCurrent && !$gen->done) {
                if ($preserveKeys) {
                    self::appendHashTableEntry($out, $gen->currentKey, $gen->currentValue);
                } else {
                    $packedKey = new Variable();
                    $packedKey->int($index++);
                    self::appendHashTableEntry($out, $packedKey, $gen->currentValue);
                }
                if (!$this->advanceGeneratorIteration($gen)) {
                    break;
                }
            }

            return $out;
        }
        if (Variable::TYPE_OBJECT === $iterator->type) {
            if (null === $frame) {
                throw new \LogicException('iterator_to_array() on Traversable object requires VM frame');
            }
            $arrayObjectCopy = $this->iteratorArrayObjectToArray($iterator, $preserveKeys);
            if (null !== $arrayObjectCopy) {
                return $arrayObjectCopy;
            }

            return $this->iteratorObjectToArray($frame, $iterator, $preserveKeys);
        }

        throw new \TypeError(
            'iterator_to_array(): Argument #1 ($iterator) must be of type '.IterableCheck::TYPE_LABEL
        );
    }

    private function iteratorArrayObjectToArray(Variable $iterable, bool $preserveKeys): ?HashTable
    {
        $iterable = $iterable->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $iterable->type) {
            return null;
        }
        $entry = $iterable->toObject();
        if (!VM\SplArraySupport::isArrayObjectClass($entry->class->name)) {
            return null;
        }
        if (!VM\SplArraySupport::hasState($entry)) {
            return null;
        }
        $table = VM\SplArraySupport::getArrayCopy($entry);
        if (null === $table) {
            return null;
        }
        if ($preserveKeys) {
            return $table;
        }
        $out = new HashTable();
        $index = 0;
        foreach ($table->iterateKeyed(true) as [, $value]) {
            $packedKey = new Variable();
            $packedKey->int($index++);
            self::appendHashTableEntry($out, $packedKey, $value);
        }

        return $out;
    }

    private function iteratorObjectToArray(Frame $frame, Variable $iterable, bool $preserveKeys): HashTable
    {
        $out = new HashTable();
        $object = ForeachIterator::resolveTraversableObject($this, $frame, $iterable);
        $this->invokeForeachInstanceMethod($frame, $object, 'rewind');
        $index = 0;
        while ($this->invokeForeachInstanceMethod($frame, $object, 'valid')->toBool()) {
            $value = $this->invokeForeachInstanceMethod($frame, $object, 'current')->resolveIndirect();
            if ($preserveKeys) {
                $key = $this->invokeForeachInstanceMethod($frame, $object, 'key')->resolveIndirect();
                self::appendHashTableEntry($out, $key, $value);
            } else {
                $packedKey = new Variable();
                $packedKey->int($index++);
                self::appendHashTableEntry($out, $packedKey, $value);
            }
            $before = $value;
            $this->invokeForeachInstanceMethod($frame, $object, 'next');
            if (!$this->invokeForeachInstanceMethod($frame, $object, 'valid')->toBool()) {
                break;
            }
            $after = $this->invokeForeachInstanceMethod($frame, $object, 'current')->resolveIndirect();
            if (self::iteratorStepStalled($before, $after) && $index > 0) {
                break;
            }
        }

        return $out;
    }

    private static function iteratorStepStalled(Variable $before, Variable $after): bool
    {
        $before = $before->resolveIndirect();
        $after = $after->resolveIndirect();
        if ($before->type !== $after->type) {
            return false;
        }
        if (Variable::TYPE_INTEGER === $before->type) {
            return $before->toInt() === $after->toInt();
        }
        if (Variable::TYPE_STRING === $before->type) {
            return $before->toString() === $after->toString();
        }

        return false;
    }

    private static function appendHashTableEntry(HashTable $out, Variable $key, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value);
        // Zend iterator_to_array / hashtable writes reject array|object|enum keys (#23573).
        $key = HashTable::normalizeIndexKey($key->resolveIndirect());
        if (Variable::TYPE_INTEGER === $key->type) {
            $out->updateIndex($key->toInt(), $copy);

            return;
        }
        $keyStr = $key->toString();
        $intKey = HashTable::tryIntFromNumericString($keyStr);
        if (null !== $intKey) {
            $out->updateIndex($intKey, $copy);

            return;
        }
        $out->update($keyStr, $copy);
    }
}
