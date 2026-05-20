<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __string__ucwords (ASCII uppercase at word starts; VmString::asciiUcwords).
 */
final class StringUcwords
{
    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__string__ucwords');
        $entry = $fn->appendBasicBlock('ucwords_main');
        $context->builder->positionAtEnd($entry);

        $string = $fn->getParam(0);
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $string);
        self::transformInPlace($context, $copy);
        $context->builder->returnValue($copy);
        $context->builder->clearInsertionPosition();
    }

    public static function transformInPlace(Context $context, Value $strPtr): void
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);

        $idxSlot = $context->builder->alloca($i64, 1, 'ucwords_idx');
        $wordStartSlot = $context->builder->alloca($i64, 1, 'ucwords_word_start');
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($one, $wordStartSlot);

        $done = BasicBlockHelper::append($context, 'ucwords_done');
        $loopHead = BasicBlockHelper::append($context, 'ucwords_head');
        $loopBody = BasicBlockHelper::append($context, 'ucwords_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $i32 = $context->getTypeFromString('int32');
        $atChar = $context->builder->gep($charPtr, $idx);
        $ch = $context->builder->load($atChar);
        $chI32 = $context->builder->zExt($ch, $i32);
        $wordStartFlag = $context->builder->load($wordStartSlot);
        $wordStart = $context->builder->icmp(Builder::INT_NE, $wordStartFlag, $zero);
        $lowerMin = $i32->constInt(ord('a'), false);
        $lowerMax = $i32->constInt(ord('z'), false);
        $inLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $lowerMin),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $lowerMax)
        );
        $shouldUpper = $context->builder->and($wordStart, $inLower);
        $upperCh = $context->builder->subNoSignedWrap($chI32, $i32->constInt(32, false));
        $newCh = $context->builder->truncOrBitCast(
            $context->builder->select($shouldUpper, $upperCh, $chI32),
            $ch->typeOf()
        );
        $context->builder->store($newCh, $atChar);
        $isWs = self::isWhitespaceByte($context, $chI32);
        $context->builder->store($context->builder->zExt($isWs, $i64), $wordStartSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
    }

    private static function isWhitespaceByte(Context $context, Value $ch): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $checks = [];
        foreach ([0x20, 0x09, 0x0A, 0x0D, 0x00, 0x0B] as $byte) {
            $checks[] = $context->builder->icmp(
                Builder::INT_EQ,
                $ch,
                $i32->constInt($byte, false)
            );
        }
        $result = $checks[0];
        for ($i = 1, $n = \count($checks); $i < $n; ++$i) {
            $result = $context->builder->or($result, $checks[$i]);
        }

        return $result;
    }
}
