<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM implementation of __compiler_filter_validate_email (issue #6082).
 *
 * php-src: ext/filter/logical_filters.c — FILTER_VALIDATE_EMAIL subset.
 * VM semantics: ext/filter/VmFilter::isValidEmailSubset().
 */
final class StringFilterEmail
{
    public static function ensureLinked(Context $context): void
    {
        $restore = $context->builder->getInsertBlock();
        self::implement($context);
        if (null !== $restore) {
            $terminator = $restore->getTerminator();
            if (null !== $terminator) {
                $context->builder->positionBefore($terminator);
            } else {
                $context->builder->positionAtEnd($restore);
            }
        }
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_filter_validate_email');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $strPtrTy = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtrTy, false, $strPtrTy);
        $fn = $context->module->addFunction('__compiler_filter_validate_email', $ft);
        self::implementValidateEmail($context, $fn);
        self::registerLinkedRuntime($context);
    }

    private static function implementValidateEmail(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('filter_email_entry');
        $context->builder->positionAtEnd($entry);

        $input = $fn->getParam(0);
        $strPtrTy = $context->getTypeFromString('__string__*');
        $nullStr = $strPtrTy->constNull();
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $maxLen = $i64->constInt(320, false);
        $atByte = $i8->constInt(ord('@'), false);
        $dotByte = $i8->constInt(ord('.'), false);

        $nullInput = $fn->appendBasicBlock('filter_email_null_input');
        $checkLen = $fn->appendBasicBlock('filter_email_check_len');
        $findAtHead = $fn->appendBasicBlock('filter_email_find_at_head');
        $findAtBody = $fn->appendBasicBlock('filter_email_find_at_body');
        $findAtInc = $fn->appendBasicBlock('filter_email_find_at_inc');
        $findAtDone = $fn->appendBasicBlock('filter_email_find_at_done');
        $checkAtPos = $fn->appendBasicBlock('filter_email_check_at_pos');
        $initDot = $fn->appendBasicBlock('filter_email_init_dot');
        $dotHead = $fn->appendBasicBlock('filter_email_dot_head');
        $dotBody = $fn->appendBasicBlock('filter_email_dot_body');
        $dotInc = $fn->appendBasicBlock('filter_email_dot_inc');
        $dotDone = $fn->appendBasicBlock('filter_email_dot_done');
        $localHead = $fn->appendBasicBlock('filter_email_local_head');
        $localBody = $fn->appendBasicBlock('filter_email_local_body');
        $localInc = $fn->appendBasicBlock('filter_email_local_inc');
        $localDone = $fn->appendBasicBlock('filter_email_local_done');
        $initLocal = $fn->appendBasicBlock('filter_email_init_local');
        $initDomain = $fn->appendBasicBlock('filter_email_init_domain');
        $domainHead = $fn->appendBasicBlock('filter_email_domain_head');
        $domainBody = $fn->appendBasicBlock('filter_email_domain_body');
        $domainInc = $fn->appendBasicBlock('filter_email_domain_inc');
        $domainDone = $fn->appendBasicBlock('filter_email_domain_done');
        $okBlock = $fn->appendBasicBlock('filter_email_ok');
        $failBlock = $fn->appendBasicBlock('filter_email_fail');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $input, $nullStr);
        $context->builder->branchIf($isNull, $nullInput, $checkLen);

        $context->builder->positionAtEnd($nullInput);
        $context->builder->returnValue($nullStr);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($checkLen);
        $len = $context->builder->load($context->builder->structGep($input, $map['length']));
        $charPtr = $context->builder->structGep($input, $map['value']);
        $atPosSlot = $context->builder->alloca($i64, 1, 'filter_email_at_pos');
        $secondAtSlot = $context->builder->alloca($i8, 1, 'filter_email_second_at');
        $findAtISlot = $context->builder->alloca($i64, 1, 'filter_email_find_at_i');
        $hasDotSlot = $context->builder->alloca($i8, 1, 'filter_email_has_dot');
        $dotISlot = $context->builder->alloca($i64, 1, 'filter_email_dot_i');
        $localISlot = $context->builder->alloca($i64, 1, 'filter_email_local_i');
        $domainISlot = $context->builder->alloca($i64, 1, 'filter_email_domain_i');
        $context->builder->store($zeroI64, $atPosSlot);
        $context->builder->store($i8->constInt(0, false), $secondAtSlot);
        $context->builder->store($zeroI64, $findAtISlot);
        $context->builder->store($i8->constInt(0, false), $hasDotSlot);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zeroI64);
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $len, $maxLen);
        $badLen = $context->builder->or($isEmpty, $tooLong);
        $context->builder->branchIf($badLen, $failBlock, $findAtHead);

        $context->builder->positionAtEnd($findAtHead);
        $findAtI = $context->builder->load($findAtISlot);
        $findAtEnd = $context->builder->icmp(Builder::INT_SGE, $findAtI, $len);
        $context->builder->branchIf($findAtEnd, $findAtDone, $findAtBody);

        $context->builder->positionAtEnd($findAtBody);
        $findAtCh = $context->builder->load($context->builder->gep($charPtr, $findAtI));
        $isAt = $context->builder->icmp(Builder::INT_EQ, $findAtCh, $atByte);
        $notAtBlock = $fn->appendBasicBlock('filter_email_find_at_not_at');
        $atFoundBlock = $fn->appendBasicBlock('filter_email_find_at_found');
        $context->builder->branchIf($isAt, $atFoundBlock, $notAtBlock);

        $context->builder->positionAtEnd($notAtBlock);
        $context->builder->branch($findAtInc);

        $context->builder->positionAtEnd($atFoundBlock);
        $prevAt = $context->builder->load($atPosSlot);
        $hadAt = $context->builder->icmp(Builder::INT_NE, $prevAt, $zeroI64);
        $markSecond = $fn->appendBasicBlock('filter_email_mark_second_at');
        $storeFirstAt = $fn->appendBasicBlock('filter_email_store_first_at');
        $context->builder->branchIf($hadAt, $markSecond, $storeFirstAt);

        $context->builder->positionAtEnd($markSecond);
        $context->builder->store($i8->constInt(1, false), $secondAtSlot);
        $context->builder->branch($findAtInc);

        $context->builder->positionAtEnd($storeFirstAt);
        $context->builder->store($findAtI, $atPosSlot);
        $context->builder->branch($findAtInc);

        $context->builder->positionAtEnd($findAtInc);
        $context->builder->store(
            $context->builder->addNoSignedWrap($findAtI, $oneI64),
            $findAtISlot
        );
        $context->builder->branch($findAtHead);

        $context->builder->positionAtEnd($findAtDone);
        $atPos = $context->builder->load($atPosSlot);
        $secondAt = $context->builder->load($secondAtSlot);
        $noAt = $context->builder->icmp(Builder::INT_EQ, $atPos, $zeroI64);
        $hasSecondAt = $context->builder->icmp(Builder::INT_NE, $secondAt, $i8->constInt(0, false));
        $badAt = $context->builder->or($noAt, $hasSecondAt);
        $context->builder->branchIf($badAt, $failBlock, $checkAtPos);

        $context->builder->positionAtEnd($checkAtPos);
        $atAtStart = $context->builder->icmp(Builder::INT_EQ, $atPos, $zeroI64);
        $atAtEnd = $context->builder->icmp(Builder::INT_EQ, $atPos, $context->builder->sub($len, $oneI64));
        $badAtPos = $context->builder->or($atAtStart, $atAtEnd);
        $context->builder->branchIf($badAtPos, $failBlock, $initDot);

        $context->builder->positionAtEnd($initDot);
        $domainStart = $context->builder->addNoSignedWrap($atPos, $oneI64);
        $context->builder->store($domainStart, $dotISlot);
        $context->builder->branch($dotHead);

        $context->builder->positionAtEnd($dotHead);
        $dotI = $context->builder->load($dotISlot);
        $dotEnd = $context->builder->icmp(Builder::INT_SGE, $dotI, $len);
        $context->builder->branchIf($dotEnd, $dotDone, $dotBody);

        $context->builder->positionAtEnd($dotBody);
        $dotCh = $context->builder->load($context->builder->gep($charPtr, $dotI));
        $isDot = $context->builder->icmp(Builder::INT_EQ, $dotCh, $dotByte);
        $dotMiss = $fn->appendBasicBlock('filter_email_dot_miss');
        $dotHit = $fn->appendBasicBlock('filter_email_dot_hit');
        $context->builder->branchIf($isDot, $dotHit, $dotMiss);

        $context->builder->positionAtEnd($dotHit);
        $context->builder->store($i8->constInt(1, false), $hasDotSlot);
        $context->builder->branch($dotDone);

        $context->builder->positionAtEnd($dotMiss);
        $context->builder->branch($dotInc);

        $context->builder->positionAtEnd($dotInc);
        $context->builder->store(
            $context->builder->addNoSignedWrap($dotI, $oneI64),
            $dotISlot
        );
        $context->builder->branch($dotHead);

        $context->builder->positionAtEnd($dotDone);
        $hasDot = $context->builder->load($hasDotSlot);
        $noDot = $context->builder->icmp(Builder::INT_EQ, $hasDot, $i8->constInt(0, false));
        $context->builder->branchIf($noDot, $failBlock, $initLocal);

        $context->builder->positionAtEnd($initLocal);
        $context->builder->store($zeroI64, $localISlot);
        $context->builder->branch($localHead);

        $context->builder->positionAtEnd($localHead);
        $localI = $context->builder->load($localISlot);
        $localEnd = $context->builder->icmp(Builder::INT_SGE, $localI, $atPos);
        $context->builder->branchIf($localEnd, $localDone, $localBody);

        $context->builder->positionAtEnd($localBody);
        $localCh = $context->builder->load($context->builder->gep($charPtr, $localI));
        $localOk = self::llvmIsLocalChar($context, $localCh);
        $localBad = $fn->appendBasicBlock('filter_email_local_bad');
        $localGood = $fn->appendBasicBlock('filter_email_local_good');
        $context->builder->branchIf($localOk, $localGood, $localBad);

        $context->builder->positionAtEnd($localBad);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($localGood);
        $context->builder->branch($localInc);

        $context->builder->positionAtEnd($localInc);
        $context->builder->store(
            $context->builder->addNoSignedWrap($localI, $oneI64),
            $localISlot
        );
        $context->builder->branch($localHead);

        $context->builder->positionAtEnd($localDone);
        $context->builder->branch($initDomain);

        $context->builder->positionAtEnd($initDomain);
        $domainStartAfterLocal = $context->builder->addNoSignedWrap($atPos, $oneI64);
        $context->builder->store($domainStartAfterLocal, $domainISlot);
        $context->builder->branch($domainHead);

        $context->builder->positionAtEnd($domainHead);
        $domainI = $context->builder->load($domainISlot);
        $domainEnd = $context->builder->icmp(Builder::INT_SGE, $domainI, $len);
        $context->builder->branchIf($domainEnd, $domainDone, $domainBody);

        $context->builder->positionAtEnd($domainBody);
        $domainCh = $context->builder->load($context->builder->gep($charPtr, $domainI));
        $domainOk = self::llvmIsDomainChar($context, $domainCh);
        $domainBad = $fn->appendBasicBlock('filter_email_domain_bad');
        $domainGood = $fn->appendBasicBlock('filter_email_domain_good');
        $context->builder->branchIf($domainOk, $domainGood, $domainBad);

        $context->builder->positionAtEnd($domainBad);
        $context->builder->branch($failBlock);

        $context->builder->positionAtEnd($domainGood);
        $context->builder->branch($domainInc);

        $context->builder->positionAtEnd($domainInc);
        $context->builder->store(
            $context->builder->addNoSignedWrap($domainI, $oneI64),
            $domainISlot
        );
        $context->builder->branch($domainHead);

        $context->builder->positionAtEnd($domainDone);
        $context->builder->branch($okBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->returnValue($input);
        $context->builder->clearInsertionPosition();

        $context->builder->positionAtEnd($failBlock);
        $context->builder->returnValue($nullStr);
        $context->builder->clearInsertionPosition();
    }

    private static function llvmIsAlnum(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $lower = self::llvmInRange($context, $ch, $i8->constInt(ord('a'), false), $i8->constInt(ord('z'), false));
        $upper = self::llvmInRange($context, $ch, $i8->constInt(ord('A'), false), $i8->constInt(ord('Z'), false));
        $digit = self::llvmInRange($context, $ch, $i8->constInt(ord('0'), false), $i8->constInt(ord('9'), false));

        return $context->builder->or($lower, $context->builder->or($upper, $digit));
    }

    private static function llvmInRange(Context $context, Value $ch, Value $min, Value $max): Value
    {
        $ge = $context->builder->icmp(Builder::INT_SGE, $ch, $min);
        $le = $context->builder->icmp(Builder::INT_SLE, $ch, $max);

        return $context->builder->and($ge, $le);
    }

    private static function llvmIsLocalChar(Context $context, Value $ch): Value
    {
        $alnum = self::llvmIsAlnum($context, $ch);
        $special = self::llvmCharInSet(
            $context,
            $ch,
            '.!#$%&\'*+/=?^_`{|}~-'
        );

        return $context->builder->or($alnum, $special);
    }

    private static function llvmIsDomainChar(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $alnum = self::llvmIsAlnum($context, $ch);
        $isDot = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('.'), false));
        $isDash = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('-'), false));

        return $context->builder->or($alnum, $context->builder->or($isDot, $isDash));
    }

    private static function llvmCharInSet(Context $context, Value $ch, string $set): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $match = $i8->constInt(0, false);
        $len = strlen($set);
        for ($i = 0; $i < $len; ++$i) {
            $eq = $context->builder->icmp(
                Builder::INT_EQ,
                $ch,
                $i8->constInt(ord($set[$i]), false)
            );
            $match = $i === 0 ? $eq : $context->builder->or($match, $eq);
        }

        return $match;
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction('__compiler_filter_validate_email');
        if (null === $fn) {
            throw new \LogicException('__compiler_filter_validate_email missing after filter email LLVM implement');
        }
        $context->registerFunction('__compiler_filter_validate_email', $fn);
    }
}
