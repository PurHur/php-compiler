<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** HashTable::getNumElements() for nested php-in-PHP JIT helpers (#14601). */
final class HashTableGetNumElements implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('getNumElements() requires a HashTable receiver');
        }

        return ArrayBuiltinHelper::getNumElements(
            $context,
            HashTableNestedReceiver::hashtableFromReceiver($context, $args[0])
        );
    }
}
