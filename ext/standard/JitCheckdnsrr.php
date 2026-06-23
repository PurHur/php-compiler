<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\CheckdnsrrRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for checkdnsrr() / dns_check_record() via CheckdnsrrRuntime PHP bridge (JIT/AOT, #9379). */
final class JitCheckdnsrr
{
    public static function invoke(Context $context, Value $hostname, Value $type): Value
    {
        CheckdnsrrRuntime::ensureLinked($context);

        $i32 = $context->getTypeFromString('int32');
        $rc = $context->builder->call(
            $context->lookupFunction('__compiler_checkdnsrr'),
            $hostname,
            $type
        );
        $truthy = $context->builder->icmp(Builder::INT_NE, $rc, $i32->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        JitValueBox::writeBool($context, $slot, $truthy);

        return $ptr;
    }

    public static function literalType(Context $context, string $type): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $cstr = $context->builder->pointerCast($context->constantFromString($type), $charPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($type), false),
            $cstr
        );
    }
}
