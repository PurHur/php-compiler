<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringPregMatch;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for preg_replace() via __compiler_preg_replace (issue #1176). */
final class JitPregReplace
{
    private static int $blockSerial = 0;

    /** @return Value __value__* (string result, or boolean false on PCRE error) */
    public static function invoke(Context $context, Value $pattern, Value $replacement, Value $subject): Value
    {
        StringPregMatch::ensureLinked($context);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $raw = $context->builder->call(
            $context->lookupFunction('__compiler_preg_replace'),
            $pattern,
            $replacement,
            $subject
        );
        $isError = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtrTy->constNull());

        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'preg_replace_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'preg_replace_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'preg_replace_done_'.$id);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isError, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call($context->lookupFunction('__value__writeString'), $ptr, $raw);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
