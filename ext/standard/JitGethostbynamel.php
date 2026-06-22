<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GethostbynamelRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gethostbynamel() via GethostbynamelRuntime PHP bridge (JIT/AOT, #9382). */
final class JitGethostbynamel
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, Value $hostname): Value
    {
        GethostbynamelRuntime::ensureLinked($context);

        $list = $context->builder->call(
            $context->lookupFunction('__compiler_gethostbynamel'),
            $hostname
        );

        return self::boxedArray($context, $list);
    }

    private static function boxedArray(Context $context, Value $listHt): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $listHt, $htPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'gethostbynamel_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'gethostbynamel_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'gethostbynamel_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $listHt
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
