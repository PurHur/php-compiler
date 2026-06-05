<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT lowering for metaphone() — mirrors ext/standard/VmMetaphone::encode().
 *
 * php-src: ext/standard/metaphone.c — PHP_FUNCTION(metaphone)
 */
final class JitMetaphone
{
    private const SH = 88;
    private const TH = 48;

    /** @var list<int> */
    private const CODES = [
        1, 16, 4, 16, 9, 2, 4, 16, 9, 2, 0, 2, 2, 2, 1, 4, 0, 2, 4, 4, 1, 0, 0, 0, 8, 0,
    ];

    private static int $blockSerial = 0;

    public static function invoke(Context $context, Value $str, Value $maxPhonemes): Value
    {
        self::ensureModuleFunction($context);
        $map = $context->structFieldMap['__string__'];
        $sep = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $len = $context->builder->load($context->builder->structGep($sep, $map['length']));
        $data = $context->builder->structGep($sep, $map['value']);

        return $context->builder->call(
            $context->lookupFunction('__compiler_metaphone'),
            $data,
            $len,
            $maxPhonemes
        );
    }

    private static function ensureModuleFunction(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_metaphone');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_metaphone', $probe);

            return;
        }
        self::implementModuleFunction($context);
    }

    private static function implementModuleFunction(Context $context): void
    {
        $ptr = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType($strPtr, false, $ptr, $i64, $i64);
        $fn = $context->module->addFunction('__compiler_metaphone', $ft);
        $entry = $fn->appendBasicBlock('metaphone_entry');
        $context->builder->positionAtEnd($entry);

        $word = $fn->getParam(0);
        $wordLen = $fn->getParam(1);
        $maxPhonemes = $fn->getParam(2);
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $negOne = $i64->constInt(-1, true);

        $maxClamped = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $maxPhonemes, $zero),
            $zero,
            $maxPhonemes
        );
        $maxIsZero = $context->builder->icmp(Builder::INT_EQ, $maxClamped, $zero);
        $bufLen = $context->builder->select(
            $maxIsZero,
            $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $wordLen, $zero),
                $one,
                $wordLen
            ),
            $maxClamped
        );
        $allocBytes = $context->builder->addNoSignedWrap($bufLen, $one);
        $malloc = $context->lookupFunction('malloc');
        $free = $context->lookupFunction('free');
        $sizeT = $context->getTypeFromString('size_t');
        $rawBuf = $context->builder->call(
            $malloc,
            $context->builder->truncOrBitCast($allocBytes, $sizeT)
        );
        $nullFail = $context->builder->icmp(Builder::INT_EQ, $rawBuf, $ptr->constNull());
        $failBb = $fn->appendBasicBlock('metaphone_malloc_fail');
        $okBb = $fn->appendBasicBlock('metaphone_ok');
        $context->builder->branchIf($nullFail, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $zero,
                $ptr->constNull()
            )
        );

        $context->builder->positionAtEnd($okBb);
        $bufSlot = $context->builder->alloca($ptr, 1);
        $lenSlot = $context->builder->alloca($i64, 1);
        $wIdxSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($rawBuf, $bufSlot);
        $context->builder->store($zero, $lenSlot);
        $context->builder->store($zero, $wIdxSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($rawBuf, $zero));

        self::skipLeadingNonAlpha($context, $fn, $word, $wordLen, $wIdxSlot, $bufSlot, $lenSlot, $maxClamped, $rawBuf, $free);
        self::prefixSwitch($context, $fn, $word, $wordLen, $wIdxSlot, $bufSlot, $lenSlot, $maxClamped);
        self::mainLoop($context, $fn, $word, $wordLen, $wIdxSlot, $bufSlot, $lenSlot, $maxClamped);

        $outLen = $context->builder->load($lenSlot);
        $outBuf = $context->builder->load($bufSlot);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $outBuf
        );
        $context->builder->call($free, $rawBuf);
        $context->builder->returnValue($result);
        $context->builder->clearInsertionPosition();
        $context->registerFunction('__compiler_metaphone', $fn);
    }

    private static function skipLeadingNonAlpha(
        Context $context,
        Value $fn,
        Value $word,
        Value $wordLen,
        Value $wIdxSlot,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes,
        Value $rawBuf,
        Value $free
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $ptr = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $id = (string) (++self::$blockSerial);
        $head = $fn->appendBasicBlock('meta_skip_head_'.$id);
        $body = $fn->appendBasicBlock('meta_skip_body_'.$id);
        $empty = $fn->appendBasicBlock('meta_skip_empty_'.$id);
        $done = $fn->appendBasicBlock('meta_skip_done_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $wIdx = $context->builder->load($wIdxSlot);
        $curr = self::letterAt($context, $word, $wordLen, $wIdx);
        $isAlpha = self::isAlphaChar($context, $curr);
        $context->builder->branchIf($isAlpha, $done, $body);

        $context->builder->positionAtEnd($empty);
        $context->builder->call($free, $rawBuf);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__string__init'),
                $zero,
                $ptr->constNull()
            )
        );

        $inc = $fn->appendBasicBlock('meta_skip_inc_'.$id);
        $context->builder->positionAtEnd($inc);
        $context->builder->store($context->builder->addNoSignedWrap($wIdx, $one), $wIdxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($body);
        $isNul = $context->builder->icmp(Builder::INT_EQ, $curr, $context->getTypeFromString('int8')->constInt(0, false));
        $context->builder->branchIf($isNul, $empty, $inc);

        $context->builder->positionAtEnd($done);
    }

    private static function prefixSwitch(
        Context $context,
        Value $fn,
        Value $word,
        Value $wordLen,
        Value $wIdxSlot,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $wIdx = $context->builder->load($wIdxSlot);
        $curr = self::letterAt($context, $word, $wordLen, $wIdx);
        $next = self::letterAt($context, $word, $wordLen, $context->builder->addNoSignedWrap($wIdx, $one));

        $done = $fn->appendBasicBlock('meta_prefix_done');
        $isA = $context->builder->icmp(Builder::INT_EQ, $curr, $i8->constInt(65, false));
        $aBb = $fn->appendBasicBlock('meta_prefix_a');
        $afterA = $fn->appendBasicBlock('meta_prefix_after_a');
        $context->builder->branchIf($isA, $aBb, $afterA);

        $context->builder->positionAtEnd($aBb);
        $isAe = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(69, false));
        $aeBb = $fn->appendBasicBlock('meta_prefix_ae');
        $aOnly = $fn->appendBasicBlock('meta_prefix_a_only');
        $context->builder->branchIf($isAe, $aeBb, $aOnly);
        $context->builder->positionAtEnd($aeBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(69, false));
        $context->builder->store($context->builder->addNoSignedWrap($wIdx, $two), $wIdxSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($aOnly);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(65, false));
        $context->builder->store($context->builder->addNoSignedWrap($wIdx, $one), $wIdxSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterA);
        self::prefixGkpWxEiou($context, $fn, $word, $wordLen, $wIdxSlot, $bufSlot, $lenSlot, $maxPhonemes, $curr, $next, $done);
        $context->builder->positionAtEnd($done);
    }

    private static function prefixGkpWxEiou(
        Context $context,
        Value $fn,
        Value $word,
        Value $wordLen,
        Value $wIdxSlot,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes,
        Value $curr,
        Value $next,
        $done
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $wIdx = $context->builder->load($wIdxSlot);

        $isGkp = self::isCharInSet($context, $curr, [71, 75, 80]);
        $gkpBb = $fn->appendBasicBlock('meta_prefix_gkp');
        $afterGkp = $fn->appendBasicBlock('meta_prefix_after_gkp');
        $context->builder->branchIf($isGkp, $gkpBb, $afterGkp);
        $context->builder->positionAtEnd($gkpBb);
        $isN = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(78, false));
        $gnBb = $fn->appendBasicBlock('meta_prefix_gn');
        $context->builder->branchIf($isN, $gnBb, $afterGkp);
        $context->builder->positionAtEnd($gnBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(78, false));
        $context->builder->store($context->builder->addNoSignedWrap($wIdx, $two), $wIdxSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterGkp);
        $isW = $context->builder->icmp(Builder::INT_EQ, $curr, $i8->constInt(87, false));
        $wBb = $fn->appendBasicBlock('meta_prefix_w');
        $afterW = $fn->appendBasicBlock('meta_prefix_after_w');
        $context->builder->branchIf($isW, $wBb, $afterW);
        $context->builder->positionAtEnd($wBb);
        $isWr = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(82, false));
        $wrBb = $fn->appendBasicBlock('meta_prefix_wr');
        $wOther = $fn->appendBasicBlock('meta_prefix_w_other');
        $context->builder->branchIf($isWr, $wrBb, $wOther);
        $context->builder->positionAtEnd($wrBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(82, false));
        $context->builder->store($context->builder->addNoSignedWrap($wIdx, $two), $wIdxSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($wOther);
        $isWh = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(72, false));
        $isVowel = self::isVowelChar($context, $next);
        $wHw = $context->builder->or($isWh, $isVowel);
        $whBb = $fn->appendBasicBlock('meta_prefix_wh');
        $context->builder->branchIf($wHw, $whBb, $afterW);
        $context->builder->positionAtEnd($whBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(87, false));
        $context->builder->store($context->builder->addNoSignedWrap($wIdx, $two), $wIdxSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterW);
        $isX = $context->builder->icmp(Builder::INT_EQ, $curr, $i8->constInt(88, false));
        $xBb = $fn->appendBasicBlock('meta_prefix_x');
        $afterX = $fn->appendBasicBlock('meta_prefix_after_x');
        $context->builder->branchIf($isX, $xBb, $afterX);
        $context->builder->positionAtEnd($xBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(83, false));
        $context->builder->store($context->builder->addNoSignedWrap($wIdx, $one), $wIdxSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterX);
        $isEiou = self::isCharInSet($context, $curr, [69, 73, 79, 85]);
        $eiouBb = $fn->appendBasicBlock('meta_prefix_eiou');
        $context->builder->branchIf($isEiou, $eiouBb, $done);
        $context->builder->positionAtEnd($eiouBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $curr);
        $context->builder->store($context->builder->addNoSignedWrap($wIdx, $one), $wIdxSlot);
        $context->builder->branch($done);
    }

    private static function mainLoop(
        Context $context,
        Value $fn,
        Value $word,
        Value $wordLen,
        Value $wIdxSlot,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $id = (string) (++self::$blockSerial);
        $head = $fn->appendBasicBlock('meta_main_head_'.$id);
        $body = $fn->appendBasicBlock('meta_main_body_'.$id);
        $done = $fn->appendBasicBlock('meta_main_done_'.$id);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $wIdx = $context->builder->load($wIdxSlot);
        $curr = self::letterAt($context, $word, $wordLen, $wIdx);
        $isNul = $context->builder->icmp(Builder::INT_EQ, $curr, $i8->constInt(0, false));
        $phoneLen = $context->builder->load($lenSlot);
        $maxIsZero = $context->builder->icmp(Builder::INT_EQ, $maxPhonemes, $zero);
        $underMax = $context->builder->icmp(Builder::INT_SLT, $phoneLen, $maxPhonemes);
        $continueMax = $context->builder->or($maxIsZero, $underMax);
        $stop = $context->builder->or($isNul, $context->builder->not($continueMax));
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $isAlpha = self::isAlphaChar($context, $curr);
        $skipBb = $fn->appendBasicBlock('meta_main_skip_'.$id);
        $dupBb = $fn->appendBasicBlock('meta_main_dup_'.$id);
        $switchBb = $fn->appendBasicBlock('meta_main_switch_'.$id);
        $context->builder->branchIf($isAlpha, $dupBb, $skipBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->store($context->builder->addNoSignedWrap($wIdx, $one), $wIdxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($dupBb);
        $prev = self::letterAt($context, $word, $wordLen, $context->builder->addNoSignedWrap($wIdx, $i64->constInt(-1, true)));
        $same = $context->builder->icmp(Builder::INT_EQ, $curr, $prev);
        $notC = $context->builder->icmp(Builder::INT_NE, $curr, $i8->constInt(67, false));
        $dup = $context->builder->and($same, $notC);
        $context->builder->branchIf($dup, $skipBb, $switchBb);

        $context->builder->positionAtEnd($switchBb);
        $skipSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $skipSlot);
        self::dispatchLetter($context, $fn, $word, $wordLen, $wIdxSlot, $bufSlot, $lenSlot, $maxPhonemes, $curr, $skipSlot);
        $skip = $context->builder->load($skipSlot);
        $wIdxNow = $context->builder->load($wIdxSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->addNoSignedWrap($wIdxNow, $one), $skip),
            $wIdxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function dispatchLetter(
        Context $context,
        Value $fn,
        Value $word,
        Value $wordLen,
        Value $wIdxSlot,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes,
        Value $curr,
        Value $skipSlot
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $wIdx = $context->builder->load($wIdxSlot);
        $next = self::letterAt($context, $word, $wordLen, $context->builder->addNoSignedWrap($wIdx, $one));
        $prev = self::letterAt($context, $word, $wordLen, $context->builder->addNoSignedWrap($wIdx, $i64->constInt(-1, true)));
        $after = self::letterAt($context, $word, $wordLen, $context->builder->addNoSignedWrap($wIdx, $two));
        $done = $fn->appendBasicBlock('meta_dispatch_done');

        $chain = $fn->appendBasicBlock('meta_dispatch_chain');
        $context->builder->branch($chain);
        $fallthrough = $chain;
        $checks = [
            [66, static fn () => self::handleB($context, $bufSlot, $lenSlot, $maxPhonemes, $prev)],
            [67, static fn () => self::handleC($context, $fn, $bufSlot, $lenSlot, $maxPhonemes, $prev, $next, $after, $skipSlot)],
            [68, static fn () => self::handleD($context, $fn, $bufSlot, $lenSlot, $maxPhonemes, $next, $after, $skipSlot)],
            [71, static fn () => self::handleG($context, $fn, $word, $wordLen, $wIdxSlot, $bufSlot, $lenSlot, $maxPhonemes, $prev, $next, $after, $skipSlot)],
            [72, static fn () => self::handleH($context, $bufSlot, $lenSlot, $maxPhonemes, $prev, $next)],
            [75, static fn () => self::handleK($context, $bufSlot, $lenSlot, $maxPhonemes, $prev)],
            [80, static fn () => self::handleP($context, $bufSlot, $lenSlot, $maxPhonemes, $next)],
            [81, static fn () => self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(75, false))],
            [83, static fn () => self::handleS($context, $fn, $word, $wordLen, $wIdxSlot, $bufSlot, $lenSlot, $maxPhonemes, $next, $after, $skipSlot)],
            [84, static fn () => self::handleT($context, $fn, $bufSlot, $lenSlot, $maxPhonemes, $next, $after, $skipSlot)],
            [86, static fn () => self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(70, false))],
            [87, static fn () => self::handleW($context, $bufSlot, $lenSlot, $maxPhonemes, $next)],
            [88, static fn () => self::handleX($context, $bufSlot, $lenSlot, $maxPhonemes)],
            [89, static fn () => self::handleY($context, $bufSlot, $lenSlot, $maxPhonemes, $next)],
            [90, static fn () => self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(83, false))],
            [70, static fn () => self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $curr)],
            [74, static fn () => self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $curr)],
            [76, static fn () => self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $curr)],
            [77, static fn () => self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $curr)],
            [78, static fn () => self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $curr)],
            [82, static fn () => self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $curr)],
        ];
        foreach ($checks as [$ord, $handler]) {
            $nextBb = $fn->appendBasicBlock('meta_dispatch_next_'.$ord);
            $runBb = $fn->appendBasicBlock('meta_dispatch_run_'.$ord);
            $context->builder->positionAtEnd($fallthrough);
            $match = $context->builder->icmp(Builder::INT_EQ, $curr, $i8->constInt($ord, false));
            $context->builder->branchIf($match, $runBb, $nextBb);
            $context->builder->positionAtEnd($runBb);
            $handler();
            $context->builder->branch($done);
            $fallthrough = $nextBb;
        }
        $context->builder->positionAtEnd($fallthrough);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
    }

    private static function handleB(Context $context, Value $bufSlot, Value $lenSlot, Value $maxPhonemes, Value $prev): void
    {
        $i8 = $context->getTypeFromString('int8');
        $notM = $context->builder->icmp(Builder::INT_NE, $prev, $i8->constInt(77, false));
        $doBb = BasicBlockHelper::append($context, 'meta_b_do');
        $doneBb = BasicBlockHelper::append($context, 'meta_b_done');
        $context->builder->branchIf($notM, $doBb, $doneBb);
        $context->builder->positionAtEnd($doBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(66, false));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function handleC(
        Context $context,
        Value $fn,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes,
        Value $prev,
        Value $next,
        Value $after,
        Value $skipSlot
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $soft = self::makeSoftChar($context, $next);
        $softBb = $fn->appendBasicBlock('meta_c_soft');
        $hardBb = $fn->appendBasicBlock('meta_c_hard');
        $context->builder->branchIf($soft, $softBb, $hardBb);
        $context->builder->positionAtEnd($softBb);
        $isCia = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $after, $i8->constInt(65, false)),
            $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(73, false))
        );
        $isS = $context->builder->icmp(Builder::INT_EQ, $prev, $i8->constInt(83, false));
        $shBb = $fn->appendBasicBlock('meta_c_sh');
        $sBb = $fn->appendBasicBlock('meta_c_s');
        $sEmitBb = $fn->appendBasicBlock('meta_c_s_emit');
        $cDone = $fn->appendBasicBlock('meta_c_soft_done');
        $context->builder->branchIf($isCia, $shBb, $sBb);
        $context->builder->positionAtEnd($shBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(self::SH, false));
        $context->builder->branch($cDone);
        $context->builder->positionAtEnd($sBb);
        $context->builder->branchIf($isS, $cDone, $sEmitBb);
        $context->builder->positionAtEnd($sEmitBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(83, false));
        $context->builder->branch($cDone);
        $context->builder->positionAtEnd($hardBb);
        $isCh = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(72, false));
        $chBb = $fn->appendBasicBlock('meta_c_ch');
        $kBb = $fn->appendBasicBlock('meta_c_k');
        $context->builder->branchIf($isCh, $chBb, $kBb);
        $context->builder->positionAtEnd($chBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(self::SH, false));
        $context->builder->store($i64->constInt(1, false), $skipSlot);
        $context->builder->branch($cDone);
        $context->builder->positionAtEnd($kBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(75, false));
        $context->builder->branch($cDone);
        $context->builder->positionAtEnd($cDone);
    }

    private static function handleD(
        Context $context,
        Value $fn,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes,
        Value $next,
        Value $after,
        Value $skipSlot
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $isDg = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(71, false));
        $dgBb = $fn->appendBasicBlock('meta_d_dg');
        $tBb = $fn->appendBasicBlock('meta_d_t');
        $context->builder->branchIf($isDg, $dgBb, $tBb);
        $context->builder->positionAtEnd($dgBb);
        $soft = self::makeSoftChar($context, $after);
        $jBb = $fn->appendBasicBlock('meta_d_j');
        $context->builder->branchIf($soft, $jBb, $tBb);
        $context->builder->positionAtEnd($jBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(74, false));
        $context->builder->store($i64->constInt(1, false), $skipSlot);
        $context->builder->branch($tBb);
        $context->builder->positionAtEnd($tBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(84, false));
    }

    private static function handleG(
        Context $context,
        Value $fn,
        Value $word,
        Value $wordLen,
        Value $wIdxSlot,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes,
        Value $prev,
        Value $next,
        Value $after,
        Value $skipSlot
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $isGh = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(72, false));
        $ghBb = $fn->appendBasicBlock('meta_g_gh');
        $afterGh = $fn->appendBasicBlock('meta_g_after_gh');
        $context->builder->branchIf($isGh, $ghBb, $afterGh);
        $context->builder->positionAtEnd($ghBb);
        $wIdx = $context->builder->load($wIdxSlot);
        $lb3 = self::letterAt($context, $word, $wordLen, $context->builder->subNoSignedWrap($wIdx, $i64->constInt(3, false)));
        $lb4 = self::letterAt($context, $word, $wordLen, $context->builder->subNoSignedWrap($wIdx, $i64->constInt(4, false)));
        $noGh = self::noGhToFChar($context, $lb3);
        $isH4 = $context->builder->icmp(Builder::INT_EQ, $lb4, $i8->constInt(72, false));
        $skipF = $context->builder->or($noGh, $isH4);
        $fBb = $fn->appendBasicBlock('meta_g_f');
        $context->builder->branchIf($skipF, $afterGh, $fBb);
        $context->builder->positionAtEnd($fBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(70, false));
        $context->builder->store($one, $skipSlot);
        $context->builder->branch($afterGh);

        $context->builder->positionAtEnd($afterGh);
        $isGn = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(78, false));
        $gnBb = $fn->appendBasicBlock('meta_g_gn');
        $afterGn = $fn->appendBasicBlock('meta_g_after_gn');
        $context->builder->branchIf($isGn, $gnBb, $afterGn);
        $context->builder->positionAtEnd($gnBb);
        $brk = self::isBreakChar($context, $after);
        $ed = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $after, $i8->constInt(69, false)),
            $context->builder->icmp(
                Builder::INT_EQ,
                self::lookahead($context, $word, $wordLen, $wIdx, $i64->constInt(3, false)),
                $i8->constInt(68, false)
            )
        );
        $skipK = $context->builder->or($brk, $ed);
        $kBb = $fn->appendBasicBlock('meta_g_k');
        $context->builder->branchIf($skipK, $afterGn, $kBb);
        $context->builder->positionAtEnd($kBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(75, false));
        $context->builder->branch($afterGn);

        $context->builder->positionAtEnd($afterGn);
        $soft = self::makeSoftChar($context, $next);
        $notG = $context->builder->icmp(Builder::INT_NE, $prev, $i8->constInt(71, false));
        $jBb = $fn->appendBasicBlock('meta_g_j');
        $k2Bb = $fn->appendBasicBlock('meta_g_k2');
        $softG = $context->builder->and($soft, $notG);
        $context->builder->branchIf($softG, $jBb, $k2Bb);
        $context->builder->positionAtEnd($jBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(74, false));
        $context->builder->branch($k2Bb);
        $context->builder->positionAtEnd($k2Bb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(75, false));
    }

    private static function handleH(Context $context, Value $bufSlot, Value $lenSlot, Value $maxPhonemes, Value $prev, Value $next): void
    {
        $i8 = $context->getTypeFromString('int8');
        $vowel = self::isVowelChar($context, $next);
        $aff = self::affectHChar($context, $prev);
        $do = $context->builder->and($vowel, $context->builder->not($aff));
        $doBb = BasicBlockHelper::append($context, 'meta_h_do');
        $doneBb = BasicBlockHelper::append($context, 'meta_h_done');
        $context->builder->branchIf($do, $doBb, $doneBb);
        $context->builder->positionAtEnd($doBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(72, false));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function handleK(Context $context, Value $bufSlot, Value $lenSlot, Value $maxPhonemes, Value $prev): void
    {
        $i8 = $context->getTypeFromString('int8');
        $notC = $context->builder->icmp(Builder::INT_NE, $prev, $i8->constInt(67, false));
        $doBb = BasicBlockHelper::append($context, 'meta_k_do');
        $doneBb = BasicBlockHelper::append($context, 'meta_k_done');
        $context->builder->branchIf($notC, $doBb, $doneBb);
        $context->builder->positionAtEnd($doBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(75, false));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function handleP(Context $context, Value $bufSlot, Value $lenSlot, Value $maxPhonemes, Value $next): void
    {
        $i8 = $context->getTypeFromString('int8');
        $isPh = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(72, false));
        $out = $context->builder->select(
            $isPh,
            $i8->constInt(70, false),
            $i8->constInt(80, false)
        );
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $out);
    }

    private static function handleS(
        Context $context,
        Value $fn,
        Value $word,
        Value $wordLen,
        Value $wIdxSlot,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes,
        Value $next,
        Value $after,
        Value $skipSlot
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $isSio = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(73, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $after, $i8->constInt(79, false)),
                $context->builder->icmp(Builder::INT_EQ, $after, $i8->constInt(65, false))
            )
        );
        $sioBb = $fn->appendBasicBlock('meta_s_sio');
        $afterSio = $fn->appendBasicBlock('meta_s_after_sio');
        $context->builder->branchIf($isSio, $sioBb, $afterSio);
        $context->builder->positionAtEnd($sioBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(self::SH, false));
        $context->builder->branch($afterSio);

        $context->builder->positionAtEnd($afterSio);
        $isSh = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(72, false));
        $shBb = $fn->appendBasicBlock('meta_s_sh');
        $afterSh = $fn->appendBasicBlock('meta_s_after_sh');
        $context->builder->branchIf($isSh, $shBb, $afterSh);
        $context->builder->positionAtEnd($shBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(self::SH, false));
        $context->builder->store($i64->constInt(1, false), $skipSlot);
        $context->builder->branch($afterSh);

        $context->builder->positionAtEnd($afterSh);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(83, false));
    }

    private static function handleT(
        Context $context,
        Value $fn,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes,
        Value $next,
        Value $after,
        Value $skipSlot
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $isTio = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(73, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $after, $i8->constInt(79, false)),
                $context->builder->icmp(Builder::INT_EQ, $after, $i8->constInt(65, false))
            )
        );
        $tioBb = $fn->appendBasicBlock('meta_t_tio');
        $afterTio = $fn->appendBasicBlock('meta_t_after_tio');
        $context->builder->branchIf($isTio, $tioBb, $afterTio);
        $context->builder->positionAtEnd($tioBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(self::SH, false));
        $context->builder->branch($afterTio);

        $context->builder->positionAtEnd($afterTio);
        $isTh = $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(72, false));
        $thBb = $fn->appendBasicBlock('meta_t_th');
        $afterTh = $fn->appendBasicBlock('meta_t_after_th');
        $context->builder->branchIf($isTh, $thBb, $afterTh);
        $context->builder->positionAtEnd($thBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(self::TH, false));
        $context->builder->store($i64->constInt(1, false), $skipSlot);
        $context->builder->branch($afterTh);

        $context->builder->positionAtEnd($afterTh);
        $isTch = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $next, $i8->constInt(67, false)),
            $context->builder->icmp(Builder::INT_EQ, $after, $i8->constInt(72, false))
        );
        $skipT = $context->builder->not($isTch);
        $tBb = $fn->appendBasicBlock('meta_t_t');
        $context->builder->branchIf($skipT, $tBb, $afterTh);
        $context->builder->positionAtEnd($tBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(84, false));
    }

    private static function handleW(Context $context, Value $bufSlot, Value $lenSlot, Value $maxPhonemes, Value $next): void
    {
        $i8 = $context->getTypeFromString('int8');
        $vowel = self::isVowelChar($context, $next);
        $doBb = BasicBlockHelper::append($context, 'meta_w_do');
        $doneBb = BasicBlockHelper::append($context, 'meta_w_done');
        $context->builder->branchIf($vowel, $doBb, $doneBb);
        $context->builder->positionAtEnd($doBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(87, false));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function handleX(Context $context, Value $bufSlot, Value $lenSlot, Value $maxPhonemes): void
    {
        $i8 = $context->getTypeFromString('int8');
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(75, false));
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(83, false));
    }

    private static function handleY(Context $context, Value $bufSlot, Value $lenSlot, Value $maxPhonemes, Value $next): void
    {
        $i8 = $context->getTypeFromString('int8');
        $vowel = self::isVowelChar($context, $next);
        $doBb = BasicBlockHelper::append($context, 'meta_y_do');
        $doneBb = BasicBlockHelper::append($context, 'meta_y_done');
        $context->builder->branchIf($vowel, $doBb, $doneBb);
        $context->builder->positionAtEnd($doBb);
        self::phonize($context, $bufSlot, $lenSlot, $maxPhonemes, $i8->constInt(89, false));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function phonize(
        Context $context,
        Value $bufSlot,
        Value $lenSlot,
        Value $maxPhonemes,
        Value $ch
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $len = $context->builder->load($lenSlot);
        $maxIsZero = $context->builder->icmp(Builder::INT_EQ, $maxPhonemes, $zero);
        $under = $context->builder->icmp(Builder::INT_SLT, $len, $maxPhonemes);
        $ok = $context->builder->or($maxIsZero, $under);
        $doBb = BasicBlockHelper::append($context, 'meta_phonize_do');
        $doneBb = BasicBlockHelper::append($context, 'meta_phonize_done');
        $context->builder->branchIf($ok, $doBb, $doneBb);
        $context->builder->positionAtEnd($doBb);
        $buf = $context->builder->load($bufSlot);
        $context->builder->store($ch, $context->builder->inBoundsGEP($buf, $len));
        $newLen = $context->builder->addNoSignedWrap($len, $one);
        $context->builder->store($newLen, $lenSlot);
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), $context->builder->inBoundsGEP($buf, $newLen));
        $context->builder->branch($doneBb);
        $context->builder->positionAtEnd($doneBb);
    }

    private static function letterAt(Context $context, Value $word, Value $wordLen, Value $index): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i8->constInt(0, false);
        $neg = $context->builder->icmp(Builder::INT_SLT, $index, $i64->constInt(0, false));
        $past = $context->builder->icmp(Builder::INT_SGE, $index, $wordLen);
        $bad = $context->builder->or($neg, $past);
        $ch = $context->builder->load($context->builder->inBoundsGEP($word, $index));
        $upper = self::toUpperChar($context, $ch);

        return $context->builder->select($bad, $zero, $upper);
    }

    private static function lookahead(Context $context, Value $word, Value $wordLen, Value $wIdx, Value $howFar): Value
    {
        return self::letterAt($context, $word, $wordLen, $context->builder->addNoSignedWrap($wIdx, $howFar));
    }

    private static function toUpperChar(Context $context, Value $ch): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $z = $context->builder->zExt($ch, $i64);
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $z, $i64->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $z, $i64->constInt(122, false))
        );
        $upper = $context->builder->select(
            $isLower,
            $context->builder->subNoSignedWrap($z, $i64->constInt(32, false)),
            $z
        );

        return $context->builder->trunc($upper, $i8);
    }

    private static function isAlphaChar(Context $context, Value $ch): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $z = $context->builder->zExt($ch, $i64);

        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $z, $i64->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $z, $i64->constInt(90, false))
        );
    }

    private static function isBreakChar(Context $context, Value $ch): Value
    {
        return $context->builder->not(self::isAlphaChar($context, $ch));
    }

    private static function encodeChar(Context $context, Value $ch): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $isAlpha = self::isAlphaChar($context, $ch);
        $z = $context->builder->zExt($ch, $i64);
        $idx = $context->builder->subNoSignedWrap($z, $i64->constInt(65, false));
        $code = self::codesLookup($context, $idx, $i8);
        $zero = $i64->constInt(0, false);

        return $context->builder->select($isAlpha, $context->builder->zExt($code, $i64), $zero);
    }

    private static function isVowelChar(Context $context, Value $ch): Value
    {
        $code = self::encodeChar($context, $ch);

        return $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($code, $context->getTypeFromString('int64')->constInt(1, false)),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
    }

    private static function makeSoftChar(Context $context, Value $ch): Value
    {
        $code = self::encodeChar($context, $ch);

        return $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($code, $context->getTypeFromString('int64')->constInt(8, false)),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
    }

    private static function affectHChar(Context $context, Value $ch): Value
    {
        $code = self::encodeChar($context, $ch);

        return $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($code, $context->getTypeFromString('int64')->constInt(4, false)),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
    }

    private static function noGhToFChar(Context $context, Value $ch): Value
    {
        $code = self::encodeChar($context, $ch);

        return $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($code, $context->getTypeFromString('int64')->constInt(16, false)),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
    }

    /**
     * @param list<int> $ords
     */
    private static function isCharInSet(Context $context, Value $ch, array $ords): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $acc = $context->getTypeFromString('int1')->constInt(0, false);
        foreach ($ords as $ord) {
            $acc = $context->builder->or(
                $acc,
                $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt($ord, false))
            );
        }

        return $acc;
    }

    private static function codesLookup(Context $context, Value $index, $i8): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i8->constInt(0, false);
        $value = $zero;
        foreach (self::CODES as $i => $code) {
            $value = $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $index, $i64->constInt($i, false)),
                $i8->constInt($code, false),
                $value
            );
        }

        return $value;
    }
}
