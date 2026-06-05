<?php

declare(strict_types=1);

/**
 * LLVM JIT/AOT helpers for str_increment() / str_decrement() (issues #3102, #3726, #5979).
 *
 * Mirrors ext/standard/VmString.php — no phpc_str_incdec.c.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(str_increment), PHP_FUNCTION(str_decrement)
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitStrIncdec
{
    private const INCREMENT_EMPTY = 'str_increment(): Argument #1 ($string) must not be empty';

    private const DECREMENT_EMPTY = 'str_decrement(): Argument #1 ($string) must not be empty';

    private const NON_ALNUM_INC = 'str_increment(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters';

    private const NON_ALNUM_DEC = 'str_decrement(): Argument #1 ($string) must be composed only of alphanumeric ASCII characters';

    private const OUT_OF_RANGE_FMT = '%s(): Argument #1 ($string) "%s" is out of decrement range';

    private const MAX_BUF = 256;

    private static int $blockSerial = 0;

    public static function increment(Context $context, Value $input, ?JITVariable $inputArg = null): Value
    {
        if (null !== $inputArg) {
            $literal = JitStringArg::compileTimeLiteral($inputArg);
            if (null !== $literal) {
                return $context->builder->load(
                    $context->constantStringFromString(VmString::strIncrement($literal))
                );
            }
        }

        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        return self::emitIncrement($context, $input);
    }

    public static function decrement(Context $context, Value $input, ?JITVariable $inputArg = null): Value
    {
        if (null !== $inputArg) {
            $literal = JitStringArg::compileTimeLiteral($inputArg);
            if (null !== $literal) {
                return $context->builder->load(
                    $context->constantStringFromString(VmString::strDecrement($literal))
                );
            }
        }

        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

        return self::emitDecrement($context, $input);
    }

    private static function emitIncrement(Context $context, Value $input): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $len = $context->builder->load($context->builder->structGep($input, $map['length']));
        $origData = $context->builder->pointerCast(
            $context->builder->structGep($input, $map['value']),
            $i8p
        );

        $id = (string) (++self::$blockSerial);
        $afterEmpty = BasicBlockHelper::append($context, 'strinc_ok_'.$id);
        $afterAlnum = BasicBlockHelper::append($context, 'strinc_alnum_'.$id);
        $loopBody = BasicBlockHelper::append($context, 'strinc_loop_'.$id);
        $noCarryBb = BasicBlockHelper::append($context, 'strinc_nocarry_'.$id);
        $carryBb = BasicBlockHelper::append($context, 'strinc_carry_body_'.$id);
        $afterLoop = BasicBlockHelper::append($context, 'strinc_after_loop_'.$id);
        $checkCarryBb = BasicBlockHelper::append($context, 'strinc_check_carry_'.$id);
        $doPrefixBb = BasicBlockHelper::append($context, 'strinc_do_prefix_'.$id);
        $plainBb = BasicBlockHelper::append($context, 'strinc_plain_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'strinc_done_'.$id);

        self::emitEmptyGuardBetween($context, $input, self::INCREMENT_EMPTY, $afterEmpty);
        $context->builder->positionAtEnd($afterEmpty);
        self::emitAlnumGuardBetween($context, $len, $origData, self::NON_ALNUM_INC, $afterAlnum);
        $context->builder->positionAtEnd($afterAlnum);

        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        $copyData = $context->builder->pointerCast(
            $context->builder->structGep($copy, $map['value']),
            $i8p
        );

        $posSlot = $context->builder->alloca($i64, 1, 'strinc_pos_'.$id);
        $carrySlot = $context->builder->alloca($i1, 1, 'strinc_carry_'.$id);
        $context->builder->store($context->builder->subNoSignedWrap($len, $one), $posSlot);
        $context->builder->store($i1->constInt(1, false), $carrySlot);
        $context->builder->branch($loopBody);

        $context->builder->positionAtEnd($loopBody);
        $pos = $context->builder->load($posSlot);
        $ch = $context->builder->load($context->builder->inBoundsGEP($copyData, $pos));
        $chI64 = $context->builder->zExt($ch, $i64);
        $isZ = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(ord('z'), false));
        $isBigZ = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(ord('Z'), false));
        $isNine = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(ord('9'), false));
        $needsCarry = $context->builder->or($isZ, $context->builder->or($isBigZ, $isNine));
        $context->builder->branchIf($needsCarry, $carryBb, $noCarryBb);

        $context->builder->positionAtEnd($noCarryBb);
        $newCh = $context->builder->trunc($context->builder->addNoSignedWrap($chI64, $one), $i8);
        $context->builder->store($newCh, $context->builder->inBoundsGEP($copyData, $pos));
        $context->builder->store($i1->constInt(0, false), $carrySlot);
        $context->builder->branch($afterLoop);

        $context->builder->positionAtEnd($carryBb);
        $isNine2 = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(ord('9'), false));
        $rolled = $context->builder->select(
            $isNine2,
            $i8->constInt(ord('0'), false),
            $context->builder->trunc($context->builder->subNoSignedWrap($chI64, $i64->constInt(25, false)), $i8)
        );
        $context->builder->store($rolled, $context->builder->inBoundsGEP($copyData, $pos));
        $context->builder->store($i1->constInt(1, false), $carrySlot);
        $context->builder->branch($afterLoop);

        $context->builder->positionAtEnd($afterLoop);
        $stillCarry = $context->builder->load($carrySlot);
        $pos = $context->builder->load($posSlot);
        $posGtZero = $context->builder->icmp(Builder::INT_SGT, $pos, $zero);
        $contLoop = $context->builder->and($stillCarry, $posGtZero);
        $context->builder->store($context->builder->subNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branchIf($contLoop, $loopBody, $checkCarryBb);

        $resultSlot = $context->builder->alloca($strPtrTy, 1, 'strinc_result_'.$id);

        $context->builder->positionAtEnd($checkCarryBb);
        $finalCarry = $context->builder->load($carrySlot);
        $context->builder->branchIf($finalCarry, $doPrefixBb, $plainBb);

        $context->builder->positionAtEnd($doPrefixBb);
        $firstCh = $context->builder->load($context->builder->inBoundsGEP($copyData, $zero));
        $firstI64 = $context->builder->zExt($firstCh, $i64);
        $isZeroLead = $context->builder->icmp(Builder::INT_EQ, $firstI64, $i64->constInt(ord('0'), false));
        $prefix = $context->builder->select(
            $isZeroLead,
            $i8->constInt(ord('1'), false),
            $firstCh
        );
        $outLen = $context->builder->addNoSignedWrap($len, $one);
        $outBuf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($outLen, $sizeT)
        );
        $outPtr = $context->builder->pointerCast($outBuf, $i8p);
        $context->builder->store($prefix, $context->builder->inBoundsGEP($outPtr, $zero));
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($context->builder->inBoundsGEP($outPtr, $one), $voidPtr),
            $context->builder->pointerCast($copyData, $voidPtr),
            $context->builder->truncOrBitCast($len, $sizeT)
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($outPtr, $outLen));
        $prefixed = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $outPtr
        );
        $context->builder->call($context->lookupFunction('free'), $outBuf);
        $context->builder->store($prefixed, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($plainBb);
        $context->builder->store($copy, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($resultSlot);
    }

    private static function emitDecrement(Context $context, Value $input): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i1 = $context->getTypeFromString('int1');
        $strPtrTy = $context->getTypeFromString('__string__*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $len = $context->builder->load($context->builder->structGep($input, $map['length']));
        $origData = $context->builder->pointerCast(
            $context->builder->structGep($input, $map['value']),
            $i8p
        );

        $id = (string) (++self::$blockSerial);
        $afterEmpty = BasicBlockHelper::append($context, 'strdec_ok_'.$id);
        $afterAlnum = BasicBlockHelper::append($context, 'strdec_alnum_'.$id);
        $afterLead = BasicBlockHelper::append($context, 'strdec_lead_'.$id);
        $loopBody = BasicBlockHelper::append($context, 'strdec_loop_'.$id);
        $noCarryBb = BasicBlockHelper::append($context, 'strdec_nocarry_'.$id);
        $carryBb = BasicBlockHelper::append($context, 'strdec_carry_body_'.$id);
        $afterLoop = BasicBlockHelper::append($context, 'strdec_after_loop_'.$id);
        $postBb = BasicBlockHelper::append($context, 'strdec_post_'.$id);
        $trimBb = BasicBlockHelper::append($context, 'strdec_trim_'.$id);
        $plainBb = BasicBlockHelper::append($context, 'strdec_plain_'.$id);
        $doneBb = BasicBlockHelper::append($context, 'strdec_done_'.$id);

        self::emitEmptyGuardBetween($context, $input, self::DECREMENT_EMPTY, $afterEmpty);
        $context->builder->positionAtEnd($afterEmpty);
        self::emitAlnumGuardBetween($context, $len, $origData, self::NON_ALNUM_DEC, $afterAlnum);
        $context->builder->positionAtEnd($afterAlnum);

        $firstCh = $context->builder->load($context->builder->inBoundsGEP($origData, $zero));
        $firstI64 = $context->builder->zExt($firstCh, $i64);
        $leadZero = $context->builder->icmp(Builder::INT_EQ, $firstI64, $i64->constInt(ord('0'), false));
        $leadErr = BasicBlockHelper::append($context, 'strdec_lead_err_'.$id);
        $context->builder->branchIf($leadZero, $leadErr, $afterLead);
        $context->builder->positionAtEnd($leadErr);
        self::emitDecrementOutOfRange($context, $origData, 'str_decrement');
        $context->builder->positionAtEnd($afterLead);

        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        $copyData = $context->builder->pointerCast(
            $context->builder->structGep($copy, $map['value']),
            $i8p
        );

        $posSlot = $context->builder->alloca($i64, 1, 'strdec_pos_'.$id);
        $carrySlot = $context->builder->alloca($i1, 1, 'strdec_carry_'.$id);
        $context->builder->store($context->builder->subNoSignedWrap($len, $one), $posSlot);
        $context->builder->store($i1->constInt(1, false), $carrySlot);
        $context->builder->branch($loopBody);

        $context->builder->positionAtEnd($loopBody);
        $pos = $context->builder->load($posSlot);
        $ch = $context->builder->load($context->builder->inBoundsGEP($copyData, $pos));
        $chI64 = $context->builder->zExt($ch, $i64);
        $isA = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(ord('a'), false));
        $isBigA = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(ord('A'), false));
        $isZero = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(ord('0'), false));
        $needsCarry = $context->builder->or($isA, $context->builder->or($isBigA, $isZero));
        $context->builder->branchIf($needsCarry, $carryBb, $noCarryBb);

        $context->builder->positionAtEnd($noCarryBb);
        $newCh = $context->builder->trunc($context->builder->subNoSignedWrap($chI64, $one), $i8);
        $context->builder->store($newCh, $context->builder->inBoundsGEP($copyData, $pos));
        $context->builder->store($i1->constInt(0, false), $carrySlot);
        $context->builder->branch($afterLoop);

        $context->builder->positionAtEnd($carryBb);
        $isZero2 = $context->builder->icmp(Builder::INT_EQ, $chI64, $i64->constInt(ord('0'), false));
        $rolled = $context->builder->select(
            $isZero2,
            $i8->constInt(ord('9'), false),
            $context->builder->trunc($context->builder->addNoSignedWrap($chI64, $i64->constInt(25, false)), $i8)
        );
        $context->builder->store($rolled, $context->builder->inBoundsGEP($copyData, $pos));
        $context->builder->store($i1->constInt(1, false), $carrySlot);
        $context->builder->branch($afterLoop);

        $context->builder->positionAtEnd($afterLoop);
        $stillCarry = $context->builder->load($carrySlot);
        $pos = $context->builder->load($posSlot);
        $posGtZero = $context->builder->icmp(Builder::INT_SGT, $pos, $zero);
        $contLoop = $context->builder->and($stillCarry, $posGtZero);
        $context->builder->store($context->builder->subNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branchIf($contLoop, $loopBody, $postBb);

        $resultSlot = $context->builder->alloca($strPtrTy, 1, 'strdec_result_'.$id);

        $context->builder->positionAtEnd($postBb);
        $finalCarry = $context->builder->load($carrySlot);
        $copyFirst = $context->builder->load($context->builder->inBoundsGEP($copyData, $zero));
        $copyFirstI64 = $context->builder->zExt($copyFirst, $i64);
        $copyLeadZero = $context->builder->icmp(Builder::INT_EQ, $copyFirstI64, $i64->constInt(ord('0'), false));
        $lenGtOne = $context->builder->icmp(Builder::INT_SGT, $len, $one);
        $needsTrim = $context->builder->or($finalCarry, $context->builder->and($copyLeadZero, $lenGtOne));
        $context->builder->branchIf($needsTrim, $trimBb, $plainBb);

        $context->builder->positionAtEnd($trimBb);
        $lenIsOne = $context->builder->icmp(Builder::INT_EQ, $len, $one);
        $trimErr = BasicBlockHelper::append($context, 'strdec_trim_err_'.$id);
        $trimOk = BasicBlockHelper::append($context, 'strdec_trim_ok_'.$id);
        $context->builder->branchIf($lenIsOne, $trimErr, $trimOk);

        $context->builder->positionAtEnd($trimErr);
        self::emitDecrementOutOfRange($context, $origData, 'str_decrement');
        $context->builder->positionAtEnd($trimOk);
        $trimLen = $context->builder->subNoSignedWrap($len, $one);
        $trimmed = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $trimLen,
            $context->builder->inBoundsGEP($copyData, $one)
        );
        $context->builder->store($trimmed, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($plainBb);
        $context->builder->store($copy, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($resultSlot);
    }

    private static function emitEmptyGuardBetween(
        Context $context,
        Value $input,
        string $message,
        $okBlock
    ): void {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($input, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $i64->constInt(0, false));

        $id = (string) (++self::$blockSerial);
        $errBlock = BasicBlockHelper::append($context, 'strincdec_empty_err_'.$id);
        $context->builder->branchIf($isEmpty, $errBlock, $okBlock);

        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::emitValueError($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function emitAlnumGuardBetween(
        Context $context,
        Value $len,
        Value $data,
        string $message,
        $okBlock
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $max = $i64->constInt(self::MAX_BUF, false);

        $id = (string) (++self::$blockSerial);
        $tooLong = BasicBlockHelper::append($context, 'strincdec_long_err_'.$id);
        $head = BasicBlockHelper::append($context, 'strincdec_alnum_head_'.$id);
        $body = BasicBlockHelper::append($context, 'strincdec_alnum_body_'.$id);
        $next = BasicBlockHelper::append($context, 'strincdec_alnum_next_'.$id);
        $fail = BasicBlockHelper::append($context, 'strincdec_alnum_fail_'.$id);

        $tooLongCond = $context->builder->icmp(Builder::INT_SGE, $len, $max);
        $context->builder->branchIf($tooLongCond, $tooLong, $head);

        $context->builder->positionAtEnd($tooLong);
        TypeErrorRaise::emitValueError($context, $message);
        $context->builder->call($context->lookupFunction('abort'));

        $iSlot = $context->builder->alloca($i64, 1, 'strincdec_i_'.$id);
        $context->builder->store($zero, $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $okBlock, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->inBoundsGEP($data, $i));
        $chI64 = $context->builder->zExt($ch, $i64);
        $isDigit = self::charInRange($context, $chI64, ord('0'), ord('9'));
        $isUpper = self::charInRange($context, $chI64, ord('A'), ord('Z'));
        $isLower = self::charInRange($context, $chI64, ord('a'), ord('z'));
        $isAlnum = $context->builder->or($isDigit, $context->builder->or($isUpper, $isLower));
        $context->builder->branchIf($isAlnum, $next, $fail);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($fail);
        TypeErrorRaise::emitValueError($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function emitDecrementOutOfRange(Context $context, Value $strData, string $fn): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $buf = $context->builder->alloca($i8, self::MAX_BUF, 'strdec_errbuf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $fmtPtr = $context->builder->pointerCast(
            $context->constantFromString(self::OUT_OF_RANGE_FMT),
            $i8p
        );
        $fnPtr = $context->builder->pointerCast($context->constantFromString($fn), $i8p);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $bufPtr,
            $sizeT->constInt(self::MAX_BUF, false),
            $fmtPtr,
            $fnPtr,
            $strData
        );
        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $bufPtr);
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_value_error'),
            $bufPtr,
            $msgLen
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function charInRange(Context $context, Value $chI64, int $min, int $max): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI64, $i64->constInt($min, false)),
            $context->builder->icmp(Builder::INT_SLE, $chI64, $i64->constInt($max, false))
        );
    }
}
