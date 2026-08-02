<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableReplaceRecursiveLlvm;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * HashTable::replaceRecursiveCopy() for NestedJIT (#26977).
 *
 * Must not NestedJIT-compile HashTable.php (#12910) — emit LLVM via
 * {@see HashTableReplaceRecursiveLlvm} (ported from ArrayBuiltinHelper #3166).
 */
final class HashTableReplaceRecursiveCopy implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('replaceRecursiveCopy() requires a HashTable receiver');
        }
        $left = HashTableNestedReceiver::hashtableFromReceiver($context, $args[0]);
        if (1 === \count($args)) {
            return HashTableReplaceRecursiveLlvm::replaceSingle($context, $left);
        }
        $result = HashTableReplaceRecursiveLlvm::replaceSingle($context, $left);
        for ($i = 1, $n = \count($args); $i < $n; ++$i) {
            $right = HashTableNestedReceiver::hashtableFromReceiver($context, $args[$i]);
            $result = HashTableReplaceRecursiveLlvm::replaceTwo($context, $result, $right);
        }

        return $result;
    }
}
