<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableWriteLlvm;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** HashTable::mergeStringKeysFrom() for nested php-in-PHP JIT helpers (#21564, SessionStorageJitHelper). */
final class HashTableMergeStringKeysFrom implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('mergeStringKeysFrom() requires HashTable receiver and source HashTable');
        }
        $dest = self::receiverVariable($context, $args[0]);
        $src = self::receiverVariable($context, $args[1]);
        HashTableWriteLlvm::spreadInto($context, $dest, $src);

        return HashTableNestedReceiver::nullVariableResult($context);
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
