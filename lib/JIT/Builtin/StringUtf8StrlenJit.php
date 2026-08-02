<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM body for __compiler_utf8_strlen on {@see __string__*} (#158, restored #27051).
 *
 * NestedJIT Utf8JitHelper→VmString mis-reads {@see __string__*} as boxed {@see __value__*}
 * under thin AOT (silent 0). Algorithm matches {@see \PHPCompiler\ext\standard\VmString::utf8CharLength}.
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_strlen)
 */
final class StringUtf8StrlenJit
{
    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_utf8_strlen');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_utf8_strlen', $probe);

            return;
        }

        $fn = null !== $probe
            ? $probe
            : $context->lookupFunction('__compiler_utf8_strlen');
        self::emitBody($context, $fn);
        $context->registerFunction('__compiler_utf8_strlen', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitBody(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('utf8_strlen_entry');
        $context->builder->positionAtEnd($entry);

        $input = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $nullStr = $strPtr->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $input, $nullStr);
        $nullBb = $fn->appendBasicBlock('utf8_strlen_null');
        $workBb = $fn->appendBasicBlock('utf8_strlen_work');
        $context->builder->branchIf($isNull, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($workBb);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $input);
        $src = self::stringData($context, $input);

        $iSlot = $context->builder->alloca($i64, 1);
        $countSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);
        $context->builder->store($zero, $countSlot);

        $head = $fn->appendBasicBlock('utf8_strlen_head');
        $body = $fn->appendBasicBlock('utf8_strlen_body');
        $inc = $fn->appendBasicBlock('utf8_strlen_inc');
        $done = $fn->appendBasicBlock('utf8_strlen_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $slen);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $b = $context->builder->load($context->builder->gep($src, $i));
        $stepSlot = $context->builder->alloca($i64, 1);
        self::emitUtf8Step($context, $fn, $b, $i, $slen, $stepSlot, $inc);

        $context->builder->positionAtEnd($inc);
        $step = $context->builder->load($stepSlot);
        $count = $context->builder->load($countSlot);
        $context->builder->store($context->builder->addNoSignedWrap($count, $one), $countSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $step), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($countSlot));
    }

    private static function emitUtf8Step(
        Context $context,
        LlvmFunction $fn,
        Value $b,
        Value $i,
        Value $slen,
        Value $stepSlot,
        \PHPLLVM\BasicBlock $incBb
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $four = $i64->constInt(4, false);

        $asciiMax = $i8->constInt(0x80, false);
        $isAscii = $context->builder->icmp(Builder::INT_ULT, $b, $asciiMax);
        $asciiBb = $fn->appendBasicBlock('utf8_strlen_step_ascii');
        $check2 = $fn->appendBasicBlock('utf8_strlen_step_check2');
        $check3 = $fn->appendBasicBlock('utf8_strlen_step_check3');
        $check4 = $fn->appendBasicBlock('utf8_strlen_step_check4');
        $fallback = $fn->appendBasicBlock('utf8_strlen_step_fallback');
        $context->builder->branchIf($isAscii, $asciiBb, $check2);

        $context->builder->positionAtEnd($asciiBb);
        $context->builder->store($one, $stepSlot);
        $context->builder->branch($incBb);

        $context->builder->positionAtEnd($check2);
        self::emitUtf8LeadBranch($context, $fn, $b, $i, $slen, $stepSlot, $incBb, $check3, 0xE0, 0xC0, $one, $two);

        $context->builder->positionAtEnd($check3);
        self::emitUtf8LeadBranch($context, $fn, $b, $i, $slen, $stepSlot, $incBb, $check4, 0xF0, 0xE0, $two, $three);

        $context->builder->positionAtEnd($check4);
        self::emitUtf8LeadBranch($context, $fn, $b, $i, $slen, $stepSlot, $incBb, $fallback, 0xF8, 0xF0, $three, $four);

        $context->builder->positionAtEnd($fallback);
        $context->builder->store($one, $stepSlot);
        $context->builder->branch($incBb);
    }

    private static function emitUtf8LeadBranch(
        Context $context,
        LlvmFunction $fn,
        Value $b,
        Value $i,
        Value $slen,
        Value $stepSlot,
        \PHPLLVM\BasicBlock $incBb,
        \PHPLLVM\BasicBlock $nextBb,
        int $mask,
        int $value,
        Value $minRemain,
        Value $step
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $maskVal = $i8->constInt($mask, false);
        $valueVal = $i8->constInt($value, false);

        $masked = $context->builder->and($b, $maskVal);
        $matches = $context->builder->icmp(Builder::INT_EQ, $masked, $valueVal);
        $limit = $context->builder->addNoSignedWrap($i, $minRemain);
        $hasRoom = $context->builder->icmp(Builder::INT_SLT, $limit, $slen);
        $ok = $context->builder->and($matches, $hasRoom);

        $okBb = $fn->appendBasicBlock('utf8_strlen_step_ok_'.$mask);
        $context->builder->branchIf($ok, $okBb, $nextBb);

        $context->builder->positionAtEnd($okBb);
        $context->builder->store($step, $stepSlot);
        $context->builder->branch($incBb);
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }
}
