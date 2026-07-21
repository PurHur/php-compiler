<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** HashTable::find() for nested php-in-PHP JIT helpers (#21849, SessionStorageJitHelper::readCookieId). */
final class HashTableFind implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('find() requires HashTable receiver and string key');
        }
        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        $keyStr = JitStringArg::lower($context, $args[1], 'HashTable::find key');
        $fetched = HashTableHelper::readStringKeyToValueBox($context, $ht, $keyStr);

        return $fetched->value;
    }
}
