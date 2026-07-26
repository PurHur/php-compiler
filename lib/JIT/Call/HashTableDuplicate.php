<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableCowLlvm;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::duplicate() for nested php-in-PHP JIT helpers (#23548 / #18451).
 *
 * Must not call HashTableDuplicateRuntime — that NestedJIT-compiles HashTableJitHelper
 * which invokes this method (circular bridge).
 */
final class HashTableDuplicate implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('duplicate() requires a HashTable receiver');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);

        return HashTableCowLlvm::duplicate($context, $ht);
    }
}
