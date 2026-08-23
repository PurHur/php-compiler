<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM body for __compiler_utf8_valid on {@see __string__*} (#4571, restored #27051, #33001, #34254).
 *
 * Owns the ABI module-locally: {@see getNamedFunction} first, then {@see addFunction}
 * if absent. Do not re-add empty always-on shells in {@see Type} — leftover decls mint
 * utf8_valid.1 (#31894 / #32122). Peer {@see StringUtf8StrlenJit}.
 * Algorithm matches {@see \PHPCompiler\ext\standard\VmString::utf8SequenceValidAt}
 * (continuation bytes, overlong min codepoint, surrogates).
 * php-src: ext/mbstring/mbstring.c — mb_check_encoding UTF-8 path
 */
final class StringUtf8ValidJit
{
    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_utf8_valid');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_utf8_valid', $probe);

            return;
        }

        $fn = self::declareAbi($context, $probe);
        self::emitBody($context, $fn);
        $context->registerFunction('__compiler_utf8_valid', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareAbi(Context $context, ?LlvmFunction $probe): LlvmFunction
    {
        if (null !== $probe) {
            return $probe;
        }

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');

        return $context->module->addFunction(
            '__compiler_utf8_valid',
            $context->context->functionType($i64, false, $strPtr)
        );
    }

    private static function emitBody(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('utf8_valid_entry');
        $context->builder->positionAtEnd($entry);

        $input = $fn->getParam(0);
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $nullStr = $strPtr->constNull();

        $isNull = $context->builder->icmp(Builder::INT_EQ, $input, $nullStr);
        $nullBb = $fn->appendBasicBlock('utf8_valid_null');
        $workBb = $fn->appendBasicBlock('utf8_valid_work');
        $context->builder->branchIf($isNull, $nullBb, $workBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($one);

        $context->builder->positionAtEnd($workBb);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $input);
        $src = self::stringData($context, $input);

        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        $head = $fn->appendBasicBlock('utf8_valid_head');
        $body = $fn->appendBasicBlock('utf8_valid_body');
        $inc = $fn->appendBasicBlock('utf8_valid_inc');
        $done = $fn->appendBasicBlock('utf8_valid_done');
        $invalid = $fn->appendBasicBlock('utf8_valid_invalid');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $slen);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $b = $context->builder->load($context->builder->gep($src, $i));
        $stepSlot = $context->builder->alloca($i64, 1);
        self::emitUtf8Step($context, $fn, $src, $b, $i, $slen, $stepSlot, $inc, $invalid);

        $context->builder->positionAtEnd($inc);
        $step = $context->builder->load($stepSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $step), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($invalid);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($one);
    }

    /**
     * Mirror {@see \PHPCompiler\ext\standard\VmString::utf8SequenceValidAt}.
     */
    private static function emitUtf8Step(
        Context $context,
        LlvmFunction $fn,
        Value $src,
        Value $b,
        Value $i,
        Value $slen,
        Value $stepSlot,
        \PHPLLVM\BasicBlock $incBb,
        \PHPLLVM\BasicBlock $invalidBb
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $four = $i64->constInt(4, false);

        $asciiMax = $i8->constInt(0x80, false);
        $isAscii = $context->builder->icmp(Builder::INT_ULT, $b, $asciiMax);
        $asciiBb = $fn->appendBasicBlock('utf8_valid_step_ascii');
        $check2 = $fn->appendBasicBlock('utf8_valid_step_check2');
        $context->builder->branchIf($isAscii, $asciiBb, $check2);

        $context->builder->positionAtEnd($asciiBb);
        $context->builder->store($one, $stepSlot);
        $context->builder->branch($incBb);

        // 2-byte: (b & 0xE0) == 0xC0, need=1, min=0x80
        $context->builder->positionAtEnd($check2);
        $check3 = $fn->appendBasicBlock('utf8_valid_step_check3');
        self::emitSequence(
            $context,
            $fn,
            $src,
            $b,
            $i,
            $slen,
            $stepSlot,
            $incBb,
            $invalidBb,
            $check3,
            0xE0,
            0xC0,
            1,
            0x80,
            $two
        );

        // 3-byte: (b & 0xF0) == 0xE0, need=2, min=0x800
        $context->builder->positionAtEnd($check3);
        $check4 = $fn->appendBasicBlock('utf8_valid_step_check4');
        self::emitSequence(
            $context,
            $fn,
            $src,
            $b,
            $i,
            $slen,
            $stepSlot,
            $incBb,
            $invalidBb,
            $check4,
            0xF0,
            0xE0,
            2,
            0x800,
            $three
        );

        // 4-byte: (b & 0xF8) == 0xF0, need=3, min=0x10000
        $context->builder->positionAtEnd($check4);
        $fallback = $fn->appendBasicBlock('utf8_valid_step_fallback');
        self::emitSequence(
            $context,
            $fn,
            $src,
            $b,
            $i,
            $slen,
            $stepSlot,
            $incBb,
            $invalidBb,
            $fallback,
            0xF8,
            0xF0,
            3,
            0x10000,
            $four
        );

        $context->builder->positionAtEnd($fallback);
        $context->builder->branch($invalidBb);
    }

    private static function emitSequence(
        Context $context,
        LlvmFunction $fn,
        Value $src,
        Value $b,
        Value $i,
        Value $slen,
        Value $stepSlot,
        \PHPLLVM\BasicBlock $incBb,
        \PHPLLVM\BasicBlock $invalidBb,
        \PHPLLVM\BasicBlock $nextBb,
        int $mask,
        int $value,
        int $need,
        int $minCp,
        Value $step
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $maskVal = $i8->constInt($mask, false);
        $valueVal = $i8->constInt($value, false);

        $masked = $context->builder->and($b, $maskVal);
        $matches = $context->builder->icmp(Builder::INT_EQ, $masked, $valueVal);
        $matchBb = $fn->appendBasicBlock('utf8_valid_match_'.$mask);
        $context->builder->branchIf($matches, $matchBb, $nextBb);

        $context->builder->positionAtEnd($matchBb);
        // i + need < slen
        $limit = $context->builder->addNoSignedWrap($i, $i64->constInt($need, false));
        $hasRoom = $context->builder->icmp(Builder::INT_SLT, $limit, $slen);
        $roomBb = $fn->appendBasicBlock('utf8_valid_room_'.$mask);
        $context->builder->branchIf($hasRoom, $roomBb, $invalidBb);

        $context->builder->positionAtEnd($roomBb);
        // cp = byte & (0xFF >> (2 + need))
        $payloadMask = 0xFF >> (2 + $need);
        $cp = $context->builder->zExt(
            $context->builder->and($b, $i8->constInt($payloadMask, false)),
            $i64
        );

        for ($j = 1; $j <= $need; ++$j) {
            $idx = $context->builder->addNoSignedWrap($i, $i64->constInt($j, false));
            $nextByte = $context->builder->load($context->builder->gep($src, $idx));
            $contMasked = $context->builder->and($nextByte, $i8->constInt(0xC0, false));
            $isCont = $context->builder->icmp(
                Builder::INT_EQ,
                $contMasked,
                $i8->constInt(0x80, false)
            );
            $contBb = $fn->appendBasicBlock('utf8_valid_cont_'.$mask.'_'.$j);
            $context->builder->branchIf($isCont, $contBb, $invalidBb);

            $context->builder->positionAtEnd($contBb);
            $payload = $context->builder->zExt(
                $context->builder->and($nextByte, $i8->constInt(0x3F, false)),
                $i64
            );
            $cp = $context->builder->or(
                $context->builder->shl($cp, $i64->constInt(6, false)),
                $payload
            );
        }

        $minOk = $context->builder->icmp(
            Builder::INT_UGE,
            $cp,
            $i64->constInt($minCp, false)
        );
        $loSurr = $context->builder->icmp(
            Builder::INT_ULT,
            $cp,
            $i64->constInt(0xD800, false)
        );
        $hiSurr = $context->builder->icmp(
            Builder::INT_UGT,
            $cp,
            $i64->constInt(0xDFFF, false)
        );
        $notSurr = $context->builder->or($loSurr, $hiSurr);
        $cpOk = $context->builder->and($minOk, $notSurr);
        $okBb = $fn->appendBasicBlock('utf8_valid_cp_ok_'.$mask);
        $context->builder->branchIf($cpOk, $okBb, $invalidBb);

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
