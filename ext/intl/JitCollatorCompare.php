<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitStringCompare;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * LLVM lowering for Collator::compare() / collator_compare() (#28649).
 *
 * Uses {@see JitStringCompare::strcmp} (native __string__ ordering) — same fallback
 * as {@see VmCollator::compare} when ICU handle is unavailable. Done-when: "a"/"b" → -1.
 *
 * Avoid NestedJIT int-return bridges (boxed __value__* ptrToInt garbage under thin AOT).
 * php-src: ext/intl/collator/collator_compare.c — zim_Collator_compare / PHP_FUNCTION(collator_compare)
 */
final class JitCollatorCompare
{
    /**
     * @param list<JITVariable> $args collator_compare($object, $string1, $string2)
     */
    public static function invokeProcedural(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'collator_compare() expects exactly 3 arguments, %d given',
                $argc
            ));
        }

        return self::invokePair(
            $context,
            $args[1],
            $args[2],
            'collator_compare',
            1,
            2
        );
    }

    /**
     * @param list<JITVariable> $args Collator::compare($string1, $string2) — $this first
     */
    public static function invokeMethod(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'Collator::compare() expects exactly 2 arguments, %d given',
                \max(0, $argc - 1)
            ));
        }

        return self::invokePair(
            $context,
            $args[1],
            $args[2],
            'Collator::compare',
            0,
            1
        );
    }

    private static function invokePair(
        Context $context,
        JITVariable $s1,
        JITVariable $s2,
        string $function,
        int $idx1,
        int $idx2
    ): Value {
        $a = JitStringBuiltinArg::lowerZparamStr($context, $s1, $function, $idx1, 'string1');
        $b = JitStringBuiltinArg::lowerZparamStr($context, $s2, $function, $idx2, 'string2');
        $cmp = JitStringCompare::strcmp($context, $a, $b);

        $slot = JitValueBox::alloc($context);
        JitValueBox::writeLong($context, $slot, $cmp);

        return JitValueBox::pointer($context, $slot);
    }
}
