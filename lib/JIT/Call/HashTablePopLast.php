<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTablePopLastLlvm;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::popLast() for nested php-in-PHP JIT helpers (#27214).
 *
 * Pure LLVM via {@see HashTablePopLastLlvm}.
 */
final class HashTablePopLast implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('popLast() requires a HashTable receiver');
        }

        return HashTablePopLastLlvm::popLast(
            $context,
            HashTableNestedReceiver::hashtableFromReceiver($context, $args[0])
        );
    }
}
