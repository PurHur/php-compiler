<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for preg_split() via __compiler_preg_split (issue #1178). */
final class JitPregSplit
{
    private static int $blockSerial = 0;

    /** @return Value
     * (string list array, or boolean false on PCRE error) */
    public static function invoke(Context $context, Value $pattern, Value $subject): Value
    {
        StringPregMatch::ensureLinked($context);

        $htPtrTy = $context->getTypeFromString('__hashtable__*');
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_preg_split'),
            $pattern,
            $subject
        );
        $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $htPtrTy->constNull());

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'preg_split_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_split_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_split_done_'.$id);

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
