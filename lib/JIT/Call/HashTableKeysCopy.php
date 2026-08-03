<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableKeysLlvm;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::keysCopy() for nested php-in-PHP JIT helpers (#14578 phase 2, #27211).
 *
 * Pure LLVM via {@see HashTableKeysLlvm} — must not call ArrayKeysRuntime
 * (NestedJIT of ArrayKeysJitHelper would recurse; peer #27067 reverse / #23548 COW).
 */
final class HashTableKeysCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('keysCopy() requires a HashTable receiver');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);

        return HashTableKeysLlvm::keys($context, $ht);
    }
}
