<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\GethostbyaddrRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gethostbyaddr() via GethostbyaddrRuntime PHP bridge (JIT/AOT, #5854, #9474). */
final class JitGethostbyaddr
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, Value $ipAddress): Value
    {
        GethostbyaddrRuntime::ensureLinked($context);

        $host = $context->builder->call(
            $context->lookupFunction('__compiler_gethostbyaddr'),
            $ipAddress
        );

        return self::boxedString($context, $host);
    }

    private static function boxedString(Context $context, Value $hostStr): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $hostStr, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'gethostbyaddr_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'gethostbyaddr_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'gethostbyaddr_done_'.$id);
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
