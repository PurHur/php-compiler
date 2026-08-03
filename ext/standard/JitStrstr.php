<?php

declare(strict_types=1);

/**
 * LLVM lowering for strstr()/stristr()/strchr() via StringStrstr scan ABI (#14778, #27185).
 *
 * Boxes {@see StringStrstr} {@see __string__*}|null into `__value__*` string|false.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringStrstr;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrstr
{
    private static int $blockSerial = 0;

    public static function find(
        Context $context,
        Value $haystack,
        Value $needle,
        ?Value $beforeNeedle = null,
        bool $caseInsensitive = false
    ): Value {
        $id = (string) (++self::$blockSerial);
        $i8 = $context->getTypeFromString('int8');
        $before = $beforeNeedle ?? $i8->constInt(0, true);

        StringStrstr::ensureLinked($context);
        $raw = StringStrstr::invoke($context, $haystack, $needle, $before, $caseInsensitive);
        $null = $context->getTypeFromString('__string__*')->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $raw, $null);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBlock = BasicBlockHelper::append($context, 'strstr_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'strstr_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'strstr_done_'.$id);
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
