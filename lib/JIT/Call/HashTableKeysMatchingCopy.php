<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableKeysMatchingLlvm;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::keysMatchingCopy() for nested php-in-PHP JIT helpers (#14582, #18287, #27544).
 *
 * Pure LLVM via {@see HashTableKeysMatchingLlvm} — must not call ArrayKeysRuntime
 * (NestedJIT of ArrayKeysJitHelper would recurse / segfault under thin AOT; peer #27211 keysCopy).
 */
final class HashTableKeysMatchingCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 3) {
            throw new \LogicException('keysMatchingCopy() requires HashTable receiver, search value, and strict flag');
        }

        $ht = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);

        return HashTableKeysMatchingLlvm::keysMatching(
            $context,
            $ht,
            JitValueBox::valuePtrFromVariable($context, $args[1]),
            self::strictAsI1($context, $args[2])
        );
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
