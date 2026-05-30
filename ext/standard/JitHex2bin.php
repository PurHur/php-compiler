<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for hex2bin() — hex string to binary (PHP-compatible subset).
 *
 * Invalid input (odd length or non-hex) emits E_WARNING and returns boolean false.
 * php-src: ext/standard/string.c — PHP_FUNCTION(hex2bin), php_hex2bin()
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitHex2bin
{
    private const MSG_ODD_LENGTH = 'Hexadecimal input string must have an even length';

    private const MSG_INVALID_HEX = 'Input string must be hexadecimal string';

    public static function convert(Context $context, Value $strPtr): Value
    {
        $slot = JitValueBox::alloc($context);
        $outPtr = JitValueBox::pointer($context, $slot);

        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $invalid = $i64->constInt(-1, true);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'hex2bin_empty');
        $checkOddBlock = BasicBlockHelper::append($context, 'hex2bin_check_odd');
        $context->builder->branchIf($isEmpty, $emptyBlock, $checkOddBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $emptyStr
        );
        $doneBlock = BasicBlockHelper::append($context, 'hex2bin_done');
        $context->builder->branch($doneBlock);

        $failOddBlock = BasicBlockHelper::append($context, 'hex2bin_fail_odd');
        $workBlock = BasicBlockHelper::append($context, 'hex2bin_work');
        $failPairBlock = BasicBlockHelper::append($context, 'hex2bin_fail_pair');

        $context->builder->positionAtEnd($checkOddBlock);
        $oddBit = $context->builder->bitwiseAnd($len, $one);
        $isOdd = $context->builder->icmp(Builder::INT_NE, $oddBit, $zero);
        $context->builder->branchIf($isOdd, $failOddBlock, $workBlock);

        $context->builder->positionAtEnd($failOddBlock);
        self::emitWarning($context, self::MSG_ODD_LENGTH);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($failPairBlock);
        self::emitWarning($context, self::MSG_INVALID_HEX);
        JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $outLen = $context->builder->lShr($len, $one);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $destMap = $context->structFieldMap['__string__'];
        $context->builder->store(
            $outLen,
            $context->builder->structGep($dest, $destMap['length'])
        );
        $destPtr = $context->builder->structGep($dest, $destMap['value']);

        $idxSlot = $context->builder->alloca($i64, 1, 'hex2bin_idx');
        $outSlot = $context->builder->alloca($i64, 1, 'hex2bin_out');
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $outSlot);

        $loopHead = BasicBlockHelper::append($context, 'hex2bin_head');
        $loopBody = BasicBlockHelper::append($context, 'hex2bin_body');
        $loopOk = BasicBlockHelper::append($context, 'hex2bin_body_ok');
        $loopDone = BasicBlockHelper::append($context, 'hex2bin_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $hiCh = $context->builder->load($context->builder->gep($charPtr, $idx));
        $loCh = $context->builder->load($context->builder->gep($charPtr, $context->builder->addNoSignedWrap($idx, $one)));
        $hi = self::hexNibbleValue($context, $hiCh, $i64, $i8);
        $lo = self::hexNibbleValue($context, $loCh, $i64, $i8);
        $hiOk = $context->builder->icmp(Builder::INT_NE, $hi, $invalid);
        $loOk = $context->builder->icmp(Builder::INT_NE, $lo, $invalid);
        $pairOk = $context->builder->and($hiOk, $loOk);
        $context->builder->branchIf($pairOk, $loopOk, $failPairBlock);

        $context->builder->positionAtEnd($loopOk);
        $combined = $context->builder->or(
            $context->builder->shl($hi, $i64->constInt(4, false)),
            $lo
        );
        $byte = $context->builder->truncOrBitCast($combined, $i8);
        $outIdx = $context->builder->load($outSlot);
        $context->builder->store($byte, $context->builder->gep($destPtr, $outIdx));
        $context->builder->store(
            $context->builder->addNoSignedWrap($outIdx, $one),
            $outSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $two),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $dest
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $outPtr;
    }

    private static function emitWarning(Context $context, string $message): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false)
        );
    }

    /** @return Value */
    private static function hexNibbleValue(Context $context, Value $ch, $i64, $i8): Value
    {
        $ord = $context->builder->zExt($ch, $i64);
        $invalid = $i64->constInt(-1, true);

        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(ord('9'), false))
        );
        $digitVal = $context->builder->subNoSignedWrap($ord, $i64->constInt(ord('0'), false));

        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(ord('A'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(ord('F'), false))
        );
        $upperVal = $context->builder->subNoSignedWrap($ord, $i64->constInt(55, false));

        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(ord('a'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(ord('f'), false))
        );
        $lowerVal = $context->builder->subNoSignedWrap($ord, $i64->constInt(87, false));

        $alnum = $context->builder->select($isDigit, $digitVal, $invalid);
        $mixed = $context->builder->select($isUpper, $upperVal, $alnum);

        return $context->builder->select($isLower, $lowerVal, $mixed);
    }
}
