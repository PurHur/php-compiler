<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for str_rot13() (in-place on __string__separate copy).
 */
final class JitStrRot13
{
    private static int $blockSerial = 0;

    public static function rot13(Context $context, JITVariable $input): Value
    {
        if (JITVariable::TYPE_STRING !== $input->type) {
            throw new \LogicException('str_rot13() only supports strings in this compiler build');
        }
        $str = $context->helper->loadValue($input);
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        self::transformInPlace($context, $copy);

        return $copy;
    }

    public static function transformInPlace(Context $context, Value $strPtr): void
    {
        $id = (string) (++self::$blockSerial);
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $iSlot = $context->builder->alloca($i64, 1, 'str_rot13_i_'.$id);
        $context->builder->store($zero, $iSlot);

        $done = BasicBlockHelper::append($context, 'str_rot13_done_'.$id);
        $loopHead = BasicBlockHelper::append($context, 'str_rot13_head_'.$id);
        $loopBody = BasicBlockHelper::append($context, 'str_rot13_body_'.$id);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $i32 = $context->getTypeFromString('int32');
        $atChar = $context->builder->gep($charPtr, $i);
        $ch = $context->builder->load($atChar);
        $chI32 = $context->builder->zExt($ch, $i32);
        $plus13 = $i32->constInt(13, false);
        $minus13 = $i32->constInt(-13, false);

        $inAm = self::inRange($context, $chI32, ord('A'), ord('M'));
        $inNz = self::inRange($context, $chI32, ord('N'), ord('Z'));
        $inAmLower = self::inRange($context, $chI32, ord('a'), ord('m'));
        $inNzLower = self::inRange($context, $chI32, ord('n'), ord('z'));

        $rot = $context->builder->select(
            $inAm,
            $context->builder->addNoSignedWrap($chI32, $plus13),
            $chI32
        );
        $rot = $context->builder->select(
            $inNz,
            $context->builder->addNoSignedWrap($chI32, $minus13),
            $rot
        );
        $rot = $context->builder->select(
            $inAmLower,
            $context->builder->addNoSignedWrap($chI32, $plus13),
            $rot
        );
        $rot = $context->builder->select(
            $inNzLower,
            $context->builder->addNoSignedWrap($chI32, $minus13),
            $rot
        );

        $newCh = $context->builder->truncOrBitCast($rot, $ch->typeOf());
        $context->builder->store($newCh, $atChar);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
    }

    private static function inRange(Context $context, Value $chI32, int $min, int $max): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt($min, false)),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt($max, false))
        );
    }
}
