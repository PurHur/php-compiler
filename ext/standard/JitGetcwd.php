<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for getcwd() via realpath(3) on "." (JIT/AOT). */
final class JitGetcwd
{
    private static int $blockSerial = 0;

    /**
     * @return Value __string__* canonical cwd, or empty string when resolution fails
     *              (PHP maps empty to false with strict checks in getcwd_.php wrapper)
     */
    public static function invoke(Context $context): Value
    {
        $dot = $context->builder->load($context->constantStringFromString('.'));

        return JitRealpath::resolve($context, $dot);
    }

    /** @return Value __value__* (string cwd, or boolean false when path is empty) */
    public static function boxed(Context $context, Value $pathStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($pathStr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'getcwd_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'getcwd_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'getcwd_done_'.$id);
        $context->builder->branchIf($isEmpty, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $pathStr
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
