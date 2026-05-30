<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringGethostname;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gethostname() via __compiler_gethostname (JIT/AOT, issue #3465). */
final class JitGethostname
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context): Value
    {
        StringGethostname::ensureLinked($context);

        $hostStr = $context->builder->call(
            $context->lookupFunction('__compiler_gethostname')
        );

        return self::boxed($context, $hostStr);
    }

    private static function boxed(Context $context, Value $hostStr): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $null = $strPtr->constNull();
        $isNull = $context->builder->icmp(Builder::INT_EQ, $hostStr, $null);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'gethostname_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'gethostname_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'gethostname_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $hostStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
