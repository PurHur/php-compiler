<?php

declare(strict_types=1);

/**
 * LLVM lowering for strrchr() via StringStrrchr scan ABI (#15406, #27951).
 *
 * Boxes {@see StringStrrchr} {@see __string__*}|null into `__value__*` string|false.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStrrchr;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrrchr
{
    private static int $blockSerial = 0;

    public static function find(Context $context, Value $haystack, Value $needle): Value
    {
        $id = (string) (++self::$blockSerial);

        StringStrrchr::ensureLinked($context);
        $raw = StringStrrchr::invoke($context, $haystack, $needle);
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $null);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'strrchr_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'strrchr_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'strrchr_done_'.$id);
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
