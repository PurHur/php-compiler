<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ArrayValuesRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** HashTable::valuesCopy() for nested php-in-PHP JIT helpers (#14578 phase 2). */
final class HashTableValuesCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('valuesCopy() requires a HashTable receiver');
        }

        return ArrayValuesRuntime::values($context, self::receiverVariable($context, $args[0]));
    }

    private static function receiverVariable(Context $context, Variable $receiver): Variable
    {
        if (Variable::TYPE_HASHTABLE === $receiver->type) {
            return $receiver;
        }
        $htPtr = HashTableNestedReceiver::hashtableFromReceiver($context, $receiver);

        return new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $htPtr);
    }
}
