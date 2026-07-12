<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Builtin\ArrayKeysRuntime;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** HashTable::keysMatchingCopy() for nested php-in-PHP JIT helpers (#14582, #18287). */
final class HashTableKeysMatchingCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('keysMatchingCopy() requires HashTable receiver, search value, and strict flag');
        }

        return ArrayKeysRuntime::keysFiltered(
            $context,
            self::receiverVariable($context, $args[0]),
            $args[1],
            self::strictAsI1($context, $args[2])
        );
    }

    private static function receiverVariable(Context $context, Variable $receiver): Variable
    {
        if (Variable::TYPE_HASHTABLE === $receiver->type) {
            return $receiver;
        }
        $htPtr = HashTableNestedReceiver::hashtableFromReceiver($context, $receiver);

        return new Variable($context, Variable::TYPE_HASHTABLE, Variable::KIND_VALUE, $htPtr);
    }

    private static function strictAsI1(Context $context, Variable $strict): Value
    {
        return JitBoolArg::lowerBuiltinTyped(
            $context,
            $strict,
            'HashTable::keysMatchingCopy',
            'strict',
            2
        );
    }
}
