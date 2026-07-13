<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::iterate() for nested php-in-PHP JIT helpers (#14601, bootstrap-aot-link).
 *
 * Returns the receiver hashtable for {@see \PHPCompiler\JIT\IteratorHelper} foreach lowering;
 * VM {@see \PHPCompiler\VM\HashTable::iterate()} builds an ArrayIterator — nested JIT uses
 * packed/string-key iterator ops instead.
 */
final class HashTableIterate implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('iterate() requires a HashTable receiver');
        }

        if (Variable::TYPE_HASHTABLE === $args[0]->type) {
            return HashTableHelper::loadHashtablePointer($context, $args[0]);
        }

        return HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
    }
}
