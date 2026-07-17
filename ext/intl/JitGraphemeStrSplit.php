<?php

declare(strict_types=1);

namespace PHPCompiler\ext\intl;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringGraphemeStrSplit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for grapheme_str_split() via __compiler_grapheme_str_split (#19964). */
final class JitGraphemeStrSplit
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, Value $string, Value $length): Value
    {
        StringGraphemeStrSplit::ensureLinked($context);

        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_grapheme_str_split'),
            $string,
            $length
        );
        $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $htPtrTy->constNull());

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'grapheme_str_split_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'grapheme_str_split_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'grapheme_str_split_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isError, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $ptr, $raw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
