<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for file_get_contents() via {@see \PHPCompiler\JIT\Builtin\StringFileGetContents}. */
final class JitFileGetContents
{
    /** @return Value __value__* holding a string */
    public static function wrapString(Context $context, Value $str): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $str
        );

        return $ptr;
    }

    /** @return Value __value__* (string contents, or boolean false on failure) */
    public static function invoke(Context $context, Value $pathStr): Value
    {
        $contents = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $pathStr
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $failed = $context->builder->icmp(Builder::INT_EQ, $contents, $strPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $failBlock = BasicBlockHelper::append($context, 'fgc_fail');
        $okBlock = BasicBlockHelper::append($context, 'fgc_ok');
        $doneBlock = BasicBlockHelper::append($context, 'fgc_done');
        $context->builder->branchIf($failed, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $contents
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
