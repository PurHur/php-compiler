<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableCowLlvm;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::unionCopy() for nested php-in-PHP JIT helpers (#23548 / #10533).
 *
 * Must not call HashTableUnionRuntime — that NestedJIT-compiles HashTableJitHelper
 * which invokes this method (circular bridge).
 */
final class HashTableUnionCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('unionCopy() requires HashTable receiver and other HashTable');
        }
        $left = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $right = HashTableNestedReceiver::hashtableFromReceiver($context, $args[1]);

        return HashTableCowLlvm::union($context, $left, $right);
    }
}
