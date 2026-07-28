<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableShiftLlvm;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::shiftFirst() for nested php-in-PHP JIT helpers (#24025).
 *
 * Pure LLVM via {@see HashTableShiftLlvm}.
 */
final class HashTableShiftFirst implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('shiftFirst() requires a HashTable receiver');
        }

        return HashTableShiftLlvm::shiftFirst(
            $context,
            HashTableNestedReceiver::hashtableFromReceiver($context, $args[0])
        );
    }
}
