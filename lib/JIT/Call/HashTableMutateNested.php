<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\HashTableMutateNestedLlvm;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::{replacePackedValues,assignPackedList,reorderKeyedPairs}() for NestedJIT (#24157).
 *
 * PHP `array` args lower as {@see __hashtable__*} (packed lists / pair lists).
 */
final class HashTableMutateNested implements Call
{
    public function __construct(
        private readonly string $methodLc,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException($this->methodLc.'() requires HashTable receiver and list argument');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $listHt = HashTableHelper::loadHashtablePointer(
            $context,
            HashTableHelper::coerceToPackedHashtable($context, $args[1])
        );

        switch ($this->methodLc) {
            case 'replacepackedvalues':
                HashTableMutateNestedLlvm::replacePackedValues($context, $ht, $listHt);
                break;
            case 'assignpackedlist':
                HashTableMutateNestedLlvm::assignPackedList($context, $ht, $listHt);
                break;
            case 'reorderkeyedpairs':
                HashTableMutateNestedLlvm::reorderKeyedPairs($context, $ht, $listHt);
                break;
            default:
                throw new \LogicException('HashTableMutateNested does not implement '.$this->methodLc.'()');
        }

        return HashTableNestedReceiver::nullVariableResult($context);
    }
}
