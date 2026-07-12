<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ArrayKeysRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** HashTable::keysCopy() for nested php-in-PHP JIT helpers (#14578 phase 2, #18287). */
final class HashTableKeysCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('keysCopy() requires a HashTable receiver');
        }

        return ArrayKeysRuntime::keys($context, self::receiverVariable($context, $args[0]));
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
