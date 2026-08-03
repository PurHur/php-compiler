<?php

declare(strict_types=1);

/**
 * LLVM lowering for strpbrk() via StringStrpbrk length-bounded scan (#14791, #27055).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStrpbrk;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrpbrk
{
    private static int $blockSerial = 0;

    /** @return Value */
    public static function find(Context $context, Value $haystack, Value $mask): Value
    {
        $id = (string) (++self::$blockSerial);

        StringStrpbrk::ensureLinked($context);
        $raw = StringStrpbrk::invoke($context, $haystack, $mask);
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $null);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'strpbrk_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'strpbrk_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'strpbrk_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $raw
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
