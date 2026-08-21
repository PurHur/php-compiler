<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StreamIoRuntime;
use PHPCompiler\JIT\Builtin\StreamLifecycleRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for popen() via __compiler_popen (#6211 / #33430). */
final class JitPopen
{
    /** @return Value boxed stream handle or boolean false */
    public static function invoke(Context $context, Value $commandStr, Value $modeStr): Value
    {
        // Peer JitFopen/JitTmpfile — Type always-on __compiler_popen dropped (#33100).
        StreamIoRuntime::ensureLinkedForUserScriptLowering($context);
        StreamLifecycleRuntime::ensureLinkedForUserScriptLowering($context);

        $handle = $context->builder->call(
            $context->lookupFunction('__compiler_popen'),
            $commandStr,
            $modeStr
        );
        $i64 = $context->getTypeFromString('int64');
        $failed = $context->builder->icmp(Builder::INT_SLT, $handle, $i64->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'popen_fail');
        $okBlock = BasicBlockHelper::append($context, 'popen_ok');
        $doneBlock = BasicBlockHelper::append($context, 'popen_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        JitValueBox::writeLong($context, $slot, $handle);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
