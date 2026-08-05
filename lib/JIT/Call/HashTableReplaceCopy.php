<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableCowLlvm;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::replaceCopy() for NestedJIT (#27519 / ArrayReplaceJitHelper).
 *
 * Must not NestedJIT-compile HashTable.php (#12910) — emit LLVM via
 * {@see HashTableCowLlvm::duplicate()} / {@see HashTableCowLlvm::replace()}.
 *
 * Peer: {@see HashTableReplaceRecursiveCopy} (#26977).
 */
final class HashTableReplaceCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('replaceCopy() requires a HashTable receiver');
        }
        $left = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        if (1 === \count($args)) {
            return HashTableCowLlvm::duplicate($context, $left);
        }
        // Match VM: duplicate receiver, then overlay each other in place (#27519).
        $result = HashTableCowLlvm::duplicate($context, $left);
        for ($i = 1, $n = \count($args); $i < $n; ++$i) {
            $right = HashTableNestedReceiver::hashtableFromReceiver($context, $args[$i]);
            HashTableCowLlvm::overlayOnto($context, $result, $right);
        }

        return $result;
    }
}
