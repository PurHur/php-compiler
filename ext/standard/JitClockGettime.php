<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringClockGettime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitClockGettimeArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** JIT/AOT lowering for clock_gettime() (#11624). */
final class JitClockGettime
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, ?JITVariable $clockArg): Value
    {
        StringClockGettime::ensureLinked($context);

        $clockId = JitClockGettimeArg::lower($context, $clockArg, 'clock_gettime');
        $ht = $context->builder->call(
            $context->lookupFunction('__compiler_clock_gettime_assoc'),
            $clockId
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $ht, $htPtr->constNull());

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'clock_gettime_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'clock_gettime_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'clock_gettime_done_'.$id);
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $i1 = $context->getTypeFromString('int1');
        $context->builder->positionAtEnd($failBlock);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->refcount->addref($ht);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
