<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableValuesLlvm;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::valuesCopy() for nested php-in-PHP JIT helpers (#14578 phase 2, #27212).
 *
 * Pure LLVM via {@see HashTableValuesLlvm} — must not call ArrayValuesRuntime
 * (NestedJIT of ArrayValuesJitHelper would recurse; peer #27211 keys / #27067 reverse).
 */
final class HashTableValuesCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('valuesCopy() requires a HashTable receiver');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);

        return HashTableValuesLlvm::values($context, $ht);
    }
}
