<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvmsg;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for msg_remove_queue() (#28432). */
final class JitMsgRemove
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        $handle = JitMsgHandle::fromArg($context, $arg, 'msg_remove_queue');
        JitMsgHandle::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $rc = $context->builder->call(
            $context->lookupFunction('__compiler_msg_remove'),
            $handle
        );
        $ok = $context->builder->icmp(Builder::INT_NE, $rc, $i64->constInt(0, false));
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $ok);

        return $ptr;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        return JitMsgHandle::emitArgumentCountError(
            $context,
            'msg_remove_queue() expects exactly 1 argument, '.$argc.' given'
        );
    }
}
