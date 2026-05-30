<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringGettimeofday;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for gettimeofday() (#3208). */
final class JitGettimeofday
{
    public static function call(Context $context, Value $asFloat): Value
    {
        StringGettimeofday::ensureLinked($context);

        $slot = JitValueBox::alloc($context);
        $slotPtr = JitValueBox::pointer($context, $slot);
        $isFloat = $context->builder->icmp(
            Builder::INT_NE,
            $asFloat,
            $context->constantFromBool(false)
        );
        $floatBb = BasicBlockHelper::append($context, 'gettimeofday_float');
        $arrayBb = BasicBlockHelper::append($context, 'gettimeofday_array');
        $mergeBb = BasicBlockHelper::append($context, 'gettimeofday_merge');
        $context->builder->branchIf($isFloat, $floatBb, $arrayBb);

        $context->builder->positionAtEnd($floatBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $slotPtr,
            $context->builder->call($context->lookupFunction('__compiler_gettimeofday_float'))
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($arrayBb);
        $raw = $context->builder->call($context->lookupFunction('__compiler_gettimeofday_array'));
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $slotPtr,
            $raw
        );
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);

        return $slotPtr;
    }
}
