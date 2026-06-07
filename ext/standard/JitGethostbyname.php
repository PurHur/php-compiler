<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GethostbynamelRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gethostbyname() via GethostbynamelRuntime delegate (JIT/AOT, #7419). */
final class JitGethostbyname
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, Value $hostname): Value
    {
        GethostbynamelRuntime::ensureLinked($context);

        $list = $context->builder->call(
            $context->lookupFunction('__compiler_gethostbynamel'),
            $hostname
        );

        return self::boxedResult($context, $hostname, $list);
    }

    private static function boxedResult(Context $context, Value $hostname, Value $listHt): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $listHt, $htPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $missBlock = BasicBlockHelper::append($context, 'gethostbyname_miss_'.$id);
        $hitBlock = BasicBlockHelper::append($context, 'gethostbyname_hit_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'gethostbyname_done_'.$id);
        $context->builder->branchIf($isNull, $missBlock, $hitBlock);

        $context->builder->positionAtEnd($missBlock);
        $hostCopy = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $hostname
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $hostCopy
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($hitBlock);
        $sizeT = $context->getTypeFromString('size_t');
        $firstIp = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringAt'),
            $listHt,
            $sizeT->constInt(0, false)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $firstIp
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
