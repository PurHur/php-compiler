<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** HashTable::add() for nested php-in-PHP JIT helpers (#16075 / VmPregMatches). */
final class HashTableAdd implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (count($args) < 3) {
            throw new \LogicException('add() requires HashTable receiver, string key, and Variable value');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $keyPtr = JitStringArg::stringPtrFromVariable($context, $args[1]);
        HashTableHelper::setAtKeyCoercingNumericString($context, $ht, $keyPtr, $args[2]);

        return HashTableNestedReceiver::nullVariableResult($context);
    }
}
