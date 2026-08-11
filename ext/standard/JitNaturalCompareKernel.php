<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM natural-order string compare kernel (mirrors VmString::strnatcmp / strnatcasecmp).
 *
 * Quarantined from {@see \PHPCompiler\JIT\Builtin\StringNaturalCompareJit} (#30088).
 * Thin AOT cannot NestedJIT {@see NaturalCompareJitHelper} (returns 0 / segfault — #26975);
 * keep the algorithm as LLVM here; Builtin stays a thin orchestrator.
 * Replaces deleted lib/AOT/runtime/phpc_strnatcmp.c + phpc_strnatcasecmp.c (#5517).
 */
final class JitNaturalCompareKernel
{
    public static function implementStrnatcmp(Context $context): void
    {
        self::implementNamed($context, 'strnatcmp', false);
    }

    public static function implementStrnatcasecmp(Context $context): void
    {
        self::implementNamed($context, 'strnatcasecmp', true);
    }

    private static function implementNamed(Context $context, string $name, bool $caseInsensitive): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareIfMissing($context, $name);
        self::emitBody($context, $fn, $caseInsensitive);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareIfMissing(Context $context, string $name): LlvmFunction
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false, $i8p, $i8p);
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable $e) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);

            return $fn;
        }
    }

    private static function emitBody(Context $context, LlvmFunction $fn, bool $caseInsensitive): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();

        $emptySlot = $context->builder->alloca($i8, 1);
        $context->builder->store($i8->constInt(0, false), $emptySlot);
        $emptyPtr = $context->builder->pointerCast($emptySlot, $i8p);

        $aIn = $fn->getParam(0);
        $bIn = $fn->getParam(1);
        $paSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $pbSlot = BasicBlockHelper::entryAlloca($context, $i8p);

        $aIsNull = $context->builder->icmp(Builder::INT_EQ, $aIn, $nullPtr);
        $bIsNull = $context->builder->icmp(Builder::INT_EQ, $bIn, $nullPtr);
        $context->builder->store($context->builder->select($aIsNull, $emptyPtr, $aIn), $paSlot);
        $context->builder->store($context->builder->select($bIsNull, $emptyPtr, $bIn), $pbSlot);

        $mainHead = $fn->appendBasicBlock('nat_main_head');
        $context->builder->branch($mainHead);

        $context->builder->positionAtEnd($mainHead);
        $pa = $context->builder->load($paSlot);
        $pb = $context->builder->load($pbSlot);
        $cha = self::loadByte($context, $pa);
        $chb = self::loadByte($context, $pb);
        $zero8 = $i8->constInt(0, false);
        $endA = $context->builder->icmp(Builder::INT_EQ, $cha, $zero8);
        $endB = $context->builder->icmp(Builder::INT_EQ, $chb, $zero8);
        $eitherEnd = $context->builder->or($endA, $endB);

        $mainDone = $fn->appendBasicBlock('nat_main_done');
        $mainBody = $fn->appendBasicBlock('nat_main_body');
        $context->builder->branchIf($eitherEnd, $mainDone, $mainBody);

        $context->builder->positionAtEnd($mainBody);
        $bothDig = $context->builder->and(self::isDigit($context, $cha), self::isDigit($context, $chb));
        $digitBlock = $fn->appendBasicBlock('nat_digit');
        $charBlock = $fn->appendBasicBlock('nat_char');
        $afterMain = $fn->appendBasicBlock('nat_main_continue');
        $context->builder->branchIf($bothDig, $digitBlock, $charBlock);

        self::emitDigitCompare($context, $fn, $paSlot, $pbSlot, $digitBlock, $afterMain);
        self::emitCharCompare($context, $fn, $paSlot, $pbSlot, $charBlock, $afterMain, $caseInsensitive, $cha, $chb);

        $context->builder->positionAtEnd($afterMain);
        $context->builder->branch($mainHead);

        $context->builder->positionAtEnd($mainDone);
        self::emitTailCompare($context, $fn, $pa, $pb);
    }

    private static function emitDigitCompare(
        Context $context,
        LlvmFunction $fn,
        Value $paSlot,
        Value $pbSlot,
        BasicBlock $digitBlock,
        BasicBlock $continueBlock
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $zero8 = $i8->constInt(0, false);
        $zero64 = $i64->constInt(0, false);
        $one64 = $i64->constInt(1, false);

        $context->builder->positionAtEnd($digitBlock);
        $startASlot = $context->builder->alloca($i8p, 1);
        $startBSlot = $context->builder->alloca($i8p, 1);

        $skipZeroHead = $fn->appendBasicBlock('nat_skip_zero_head');
        $context->builder->branch($skipZeroHead);

        $context->builder->positionAtEnd($skipZeroHead);
        $pa = $context->builder->load($paSlot);
        $pb = $context->builder->load($pbSlot);
        $cha = self::loadByte($context, $pa);
        $chb = self::loadByte($context, $pb);
        $skipA = $context->builder->and(self::isDigit($context, $cha), $context->builder->icmp(Builder::INT_EQ, $cha, $zero8));
        $skipB = $context->builder->and(self::isDigit($context, $chb), $context->builder->icmp(Builder::INT_EQ, $chb, $zero8));
        $canSkip = $context->builder->or($skipA, $skipB);
        $skipDone = $fn->appendBasicBlock('nat_skip_zero_done');
        $skipBody = $fn->appendBasicBlock('nat_skip_zero_body');
        $context->builder->branchIf($canSkip, $skipBody, $skipDone);

        $context->builder->positionAtEnd($skipBody);
        $context->builder->store($context->builder->select($skipA, self::ptrInc($context, $pa), $pa), $paSlot);
        $context->builder->store($context->builder->select($skipB, self::ptrInc($context, $pb), $pb), $pbSlot);
        $context->builder->branch($skipZeroHead);

        $context->builder->positionAtEnd($skipDone);
        $pa = $context->builder->load($paSlot);
        $pb = $context->builder->load($pbSlot);
        $context->builder->store($pa, $startASlot);
        $context->builder->store($pb, $startBSlot);

        $scanHead = $fn->appendBasicBlock('nat_scan_digit_head');
        $context->builder->branch($scanHead);
        $context->builder->positionAtEnd($scanHead);
        $pa = $context->builder->load($paSlot);
        $stillDig = self::isDigit($context, self::loadByte($context, $pa));
        $scanDone = $fn->appendBasicBlock('nat_scan_digit_done');
        $scanBody = $fn->appendBasicBlock('nat_scan_digit_body');
        $context->builder->branchIf($stillDig, $scanBody, $scanDone);
        $context->builder->positionAtEnd($scanBody);
        $context->builder->store(self::ptrInc($context, $pa), $paSlot);
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($scanDone);
        $scanBHead = $fn->appendBasicBlock('nat_scan_b_head');
        $context->builder->branch($scanBHead);
        $context->builder->positionAtEnd($scanBHead);
        $pb = $context->builder->load($pbSlot);
        $stillDigB = self::isDigit($context, self::loadByte($context, $pb));
        $scanBDone = $fn->appendBasicBlock('nat_scan_b_done');
        $scanBBody = $fn->appendBasicBlock('nat_scan_b_body');
        $context->builder->branchIf($stillDigB, $scanBBody, $scanBDone);
        $context->builder->positionAtEnd($scanBBody);
        $context->builder->store(self::ptrInc($context, $pb), $pbSlot);
        $context->builder->branch($scanBHead);

        $context->builder->positionAtEnd($scanBDone);
        $pa = $context->builder->load($paSlot);
        $pb = $context->builder->load($pbSlot);
        $lenA = self::ptrDiff($context, $pa, $context->builder->load($startASlot));
        $lenB = self::ptrDiff($context, $pb, $context->builder->load($startBSlot));
        $bothZero = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $lenA, $zero64),
            $context->builder->icmp(Builder::INT_EQ, $lenB, $zero64)
        );
        $lenCmp = $fn->appendBasicBlock('nat_len_cmp');
        $digitContinue = $fn->appendBasicBlock('nat_digit_continue');
        $context->builder->branchIf($bothZero, $digitContinue, $lenCmp);

        $context->builder->positionAtEnd($lenCmp);
        $lenEq = $context->builder->icmp(Builder::INT_EQ, $lenA, $lenB);
        $lenCmpRet = $fn->appendBasicBlock('nat_len_cmp_ret');
        $lenLoop = $fn->appendBasicBlock('nat_digit_byte_loop');
        $context->builder->branchIf($lenEq, $lenLoop, $lenCmpRet);
        $context->builder->positionAtEnd($lenCmpRet);
        self::returnSign($context, $context->builder->sub($lenA, $lenB));

        $context->builder->positionAtEnd($lenLoop);
        $kSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero64, $kSlot);
        $byteHead = $fn->appendBasicBlock('nat_digit_byte_head');
        $context->builder->branch($byteHead);

        $context->builder->positionAtEnd($byteHead);
        $k = $context->builder->load($kSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $k, $lenA);
        $byteDone = $fn->appendBasicBlock('nat_digit_byte_done');
        $byteBody = $fn->appendBasicBlock('nat_digit_byte_body');
        $context->builder->branchIf($atEnd, $byteDone, $byteBody);

        $context->builder->positionAtEnd($byteBody);
        $i32 = $context->getTypeFromString('int32');
        $off = $context->builder->trunc($k, $i32);
        $da = self::loadByte($context, $context->builder->gep($context->builder->load($startASlot), $off));
        $db = self::loadByte($context, $context->builder->gep($context->builder->load($startBSlot), $off));
        $diff = $context->builder->sub($context->builder->zExt($da, $i64), $context->builder->zExt($db, $i64));
        $byteRet = $fn->appendBasicBlock('nat_digit_byte_ret');
        $byteNext = $fn->appendBasicBlock('nat_digit_byte_next');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_NE, $diff, $zero64), $byteRet, $byteNext);
        $context->builder->positionAtEnd($byteRet);
        self::returnSign($context, $diff);
        $context->builder->positionAtEnd($byteNext);
        $context->builder->store($context->builder->addNoSignedWrap($k, $one64), $kSlot);
        $context->builder->branch($byteHead);

        $context->builder->positionAtEnd($byteDone);
        $context->builder->branch($digitContinue);
        $context->builder->positionAtEnd($digitContinue);
        $context->builder->branch($continueBlock);
    }

    private static function emitCharCompare(
        Context $context,
        LlvmFunction $fn,
        Value $paSlot,
        Value $pbSlot,
        BasicBlock $charBlock,
        BasicBlock $continueBlock,
        bool $caseInsensitive,
        Value $cha,
        Value $chb
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $zero64 = $i64->constInt(0, false);
        $context->builder->positionAtEnd($charBlock);
        if ($caseInsensitive) {
            $cha = self::asciiLower($context, $cha);
            $chb = self::asciiLower($context, $chb);
        }
        $diff = $context->builder->sub($context->builder->zExt($cha, $i64), $context->builder->zExt($chb, $i64));
        $same = $context->builder->icmp(Builder::INT_EQ, $diff, $zero64);
        $charRet = $fn->appendBasicBlock('nat_char_ret');
        $charAdvance = $fn->appendBasicBlock('nat_char_advance');
        $context->builder->branchIf($same, $charAdvance, $charRet);
        $context->builder->positionAtEnd($charRet);
        self::returnSign($context, $diff);
        $context->builder->positionAtEnd($charAdvance);
        $context->builder->store(self::ptrInc($context, $context->builder->load($paSlot)), $paSlot);
        $context->builder->store(self::ptrInc($context, $context->builder->load($pbSlot)), $pbSlot);
        $context->builder->branch($continueBlock);
    }

    private static function emitTailCompare(Context $context, LlvmFunction $fn, Value $pa, Value $pb): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $zero8 = $i8->constInt(0, false);
        $cha = self::loadByte($context, $pa);
        $chb = self::loadByte($context, $pb);
        $endA = $context->builder->icmp(Builder::INT_EQ, $cha, $zero8);
        $endB = $context->builder->icmp(Builder::INT_EQ, $chb, $zero8);
        $bothEnd = $context->builder->and($endA, $endB);
        $retZero = $fn->appendBasicBlock('nat_tail_zero');
        $retNeg = $fn->appendBasicBlock('nat_tail_neg');
        $retPos = $fn->appendBasicBlock('nat_tail_pos');
        $tailPick = $fn->appendBasicBlock('nat_tail_pick');
        $context->builder->branchIf($bothEnd, $retZero, $tailPick);
        $context->builder->positionAtEnd($tailPick);
        $context->builder->branchIf($endA, $retNeg, $retPos);
        $context->builder->positionAtEnd($retZero);
        $context->builder->returnValue($i32->constInt(0, false));
        $context->builder->positionAtEnd($retNeg);
        $context->builder->returnValue($i32->constInt(-1, false));
        $context->builder->positionAtEnd($retPos);
        $context->builder->returnValue($i32->constInt(1, false));
    }

    private static function loadByte(Context $context, Value $ptr): Value
    {
        return $context->builder->load($ptr);
    }

    private static function isDigit(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(57, false))
        );
    }

    private static function asciiLower(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(90, false))
        );

        return $context->builder->select(
            $isUpper,
            $context->builder->add($ch, $i8->constInt(32, false)),
            $ch
        );
    }

    private static function ptrInc(Context $context, Value $ptr): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->intToPtr(
            $context->builder->addNoSignedWrap(
                $context->builder->ptrToInt($ptr, $i64),
                $i64->constInt(1, false)
            ),
            $i8p
        );
    }

    private static function ptrDiff(Context $context, Value $end, Value $start): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sub(
            $context->builder->ptrToInt($end, $i64),
            $context->builder->ptrToInt($start, $i64)
        );
    }

    private static function returnSign(Context $context, Value $diffI64): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $gt = $context->builder->icmp(Builder::INT_SGT, $diffI64, $zero);
        $lt = $context->builder->icmp(Builder::INT_SLT, $diffI64, $zero);
        $one = $i32->constInt(1, false);
        $negOne = $i32->constInt(-1, false);
        $zero32 = $i32->constInt(0, false);
        $context->builder->returnValue($context->builder->select($gt, $one, $context->builder->select($lt, $negOne, $zero32)));
    }
}
