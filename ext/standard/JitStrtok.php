<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for strtok() via StringStrtok / StringStrtokJit (issue #6111, #27645).
 */
final class JitStrtok
{
    private static int $blockSerial = 0;

    public static function tokenize(Context $context, ?Value $str, Value $tok): Value
    {
        $id = (string) (++self::$blockSerial);
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*')->constNull();
        $init = $i8->constInt(0, true);
        if (null !== $str) {
            $strPtr = $str;
            $init = $i8->constInt(1, true);
        }
        $fn = $context->lookupFunction('phpc_strtok');
        $raw = $context->builder->call($fn, $strPtr, $tok, $init);
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $null);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'strtok_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'strtok_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'strtok_done_'.$id);
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

    /** Dummy false result after compile-time TypeError abort (#19242). */
    public static function deadFalseResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));

        return $ptr;
    }
}
