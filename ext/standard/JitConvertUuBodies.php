<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM bodies for convert_uuencode/convert_uudecode (former uuencode_jit_runtime.c, #6307).
 *
 * php-src: ext/standard/uuencode.c — parity with ext/standard/VmString.php.
 */
final class JitConvertUuBodies
{
    private const UUDEC_ERR_MSG = 'convert_uudecode(): Argument #1 ($data) is not a valid uuencoded string';

    public static function implementEncodeBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('uu_enc_entry');
        $context->builder->positionAtEnd($entry);

        $str = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $fortyFive = $i64->constInt(45, false);
        $fortySix = $i64->constInt(46, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $srcLen = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcData = $context->builder->structGep($src, $map['value']);

        $cap = $context->builder->add(
            $context->builder->mul($context->builder->unsignedDiv($srcLen, $two), $three),
            $fortySix
        );
        $allocSize = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $cap, $zero),
            $cap,
            $i64->constInt(4, false)
        );
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($allocSize, $sizeT)
        );
        $bufPtr = $context->builder->pointerCast($buf, $i8p);

        $sSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $pSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $lenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $eeSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero, $sSlot);
        $context->builder->store($zero, $pSlot);
        $context->builder->store($fortyFive, $lenSlot);

        $outerHead = $fn->appendBasicBlock('uu_enc_outer_head');
        $outerBody = $fn->appendBasicBlock('uu_enc_outer_body');
        $outerDone = $fn->appendBasicBlock('uu_enc_outer_done');
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerHead);
        $s = $context->builder->load($sSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($s, $three), $srcLen),
            $outerBody,
            $outerDone
        );

        $context->builder->positionAtEnd($outerBody);
        $s = $context->builder->load($sSlot);
        $len = $context->builder->load($lenSlot);
        $ee = $context->builder->addNoSignedWrap($s, $len);
        $context->builder->store($ee, $eeSlot);
        $clampBb = $fn->appendBasicBlock('uu_enc_clamp');
        $afterClamp = $fn->appendBasicBlock('uu_enc_after_clamp');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $ee, $srcLen),
            $clampBb,
            $afterClamp
        );

        $context->builder->positionAtEnd($clampBb);
        $s = $context->builder->load($sSlot);
        $ee = $srcLen;
        $len = $context->builder->sub($ee, $s);
        $rem = $context->builder->unsigendRem($len, $three);
        $floorBb = $fn->appendBasicBlock('uu_enc_floor');
        $afterFloor = $fn->appendBasicBlock('uu_enc_after_floor');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $rem, $zero),
            $floorBb,
            $afterFloor
        );
        $context->builder->positionAtEnd($floorBb);
        $s = $context->builder->load($sSlot);
        $len = $context->builder->sub($srcLen, $s);
        $ee = $context->builder->addNoSignedWrap($s, $context->builder->mul($context->builder->unsignedDiv($len, $three), $three));
        $context->builder->store($len, $lenSlot);
        $context->builder->store($ee, $eeSlot);
        $context->builder->branch($afterFloor);
        $context->builder->positionAtEnd($afterFloor);
        $s = $context->builder->load($sSlot);
        $ee = $srcLen;
        $len = $context->builder->sub($ee, $s);
        $context->builder->store($len, $lenSlot);
        $context->builder->store($ee, $eeSlot);
        $context->builder->branch($afterClamp);

        $context->builder->positionAtEnd($afterClamp);
        $ee = $context->builder->load($eeSlot);
        $len = $context->builder->load($lenSlot);
        $p = $context->builder->load($pSlot);
        $context->builder->store(self::storeUuEncAt($context, $bufPtr, $p, $context->builder->trunc($len, $i8)), $pSlot);

        $innerHead = $fn->appendBasicBlock('uu_enc_inner_head');
        $innerBody = $fn->appendBasicBlock('uu_enc_inner_body');
        $innerDone = $fn->appendBasicBlock('uu_enc_inner_done');
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerHead);
        $s = $context->builder->load($sSlot);
        $ee = $context->builder->load($eeSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $s, $ee),
            $innerBody,
            $innerDone
        );

        $context->builder->positionAtEnd($innerBody);
        $s = $context->builder->load($sSlot);
        $p = $context->builder->load($pSlot);
        $b0 = $context->builder->zExt($context->builder->load($context->builder->gep($srcData, $s)), $i64);
        $b1 = $context->builder->zExt($context->builder->load($context->builder->gep($srcData, $context->builder->addNoSignedWrap($s, $one))), $i64);
        $b2 = $context->builder->zExt($context->builder->load($context->builder->gep($srcData, $context->builder->addNoSignedWrap($s, $two))), $i64);
        $p = self::storeUuEncAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->lShr($b0, $i64->constInt(2, false)), $i8));
        $p = self::storeUuEncAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->or(
            $context->builder->and($context->builder->shl($b0, $i64->constInt(4, false)), $i64->constInt(060, false)),
            $context->builder->and($context->builder->lShr($b1, $i64->constInt(4, false)), $i64->constInt(017, false))
        ), $i8));
        $p = self::storeUuEncAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->or(
            $context->builder->and($context->builder->shl($b1, $i64->constInt(2, false)), $i64->constInt(074, false)),
            $context->builder->and($context->builder->lShr($b2, $i64->constInt(6, false)), $i64->constInt(03, false))
        ), $i8));
        $p = self::storeUuEncAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->and($b2, $i64->constInt(077, false)), $i8));
        $context->builder->store($p, $pSlot);
        $context->builder->store($context->builder->addNoSignedWrap($s, $three), $sSlot);
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerDone);
        $len = $context->builder->load($lenSlot);
        $nlBb = $fn->appendBasicBlock('uu_enc_nl');
        $outerNext = $fn->appendBasicBlock('uu_enc_outer_next');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $len, $fortyFive),
            $nlBb,
            $outerNext
        );
        $context->builder->positionAtEnd($nlBb);
        $p = $context->builder->load($pSlot);
        $context->builder->store(self::storeByteAt($context, $bufPtr, $p, $i8->constInt(10, false)), $pSlot);
        $context->builder->branch($outerNext);
        $context->builder->positionAtEnd($outerNext);
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerDone);
        $tailCheck = $fn->appendBasicBlock('uu_enc_tail_check');
        $tailDone = $fn->appendBasicBlock('uu_enc_tail_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $context->builder->load($sSlot), $srcLen),
            $tailCheck,
            $tailDone
        );

        $context->builder->positionAtEnd($tailCheck);
        $len = $context->builder->load($lenSlot);
        $tailLenBb = $fn->appendBasicBlock('uu_enc_tail_len');
        $tailEncode = $fn->appendBasicBlock('uu_enc_tail_encode');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $len, $fortyFive),
            $tailLenBb,
            $tailEncode
        );
        $context->builder->positionAtEnd($tailLenBb);
        $s = $context->builder->load($sSlot);
        $p = $context->builder->load($pSlot);
        $remain = $context->builder->sub($srcLen, $s);
        $context->builder->store(self::storeUuEncAt($context, $bufPtr, $p, $context->builder->trunc($remain, $i8)), $pSlot);
        $context->builder->store($zero, $lenSlot);
        $context->builder->branch($tailEncode);

        $context->builder->positionAtEnd($tailEncode);
        $s = $context->builder->load($sSlot);
        $p = $context->builder->load($pSlot);
        $remain = $context->builder->sub($srcLen, $s);
        $b0 = $context->builder->zExt($context->builder->load($context->builder->gep($srcData, $s)), $i64);
        $hasB1 = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($s, $one), $srcLen);
        $b1 = $context->builder->select(
            $hasB1,
            $context->builder->zExt($context->builder->load($context->builder->gep($srcData, $context->builder->addNoSignedWrap($s, $one))), $i64),
            $zero
        );
        $hasB2 = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($s, $two), $srcLen);
        $b2 = $context->builder->select(
            $hasB2,
            $context->builder->zExt($context->builder->load($context->builder->gep($srcData, $context->builder->addNoSignedWrap($s, $two))), $i64),
            $zero
        );
        $p = self::storeUuEncAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->lShr($b0, $i64->constInt(2, false)), $i8));
        $p = self::storeUuEncAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->or(
            $context->builder->and($context->builder->shl($b0, $i64->constInt(4, false)), $i64->constInt(060, false)),
            $context->builder->and($context->builder->lShr($b1, $i64->constInt(4, false)), $i64->constInt(017, false))
        ), $i8));
        $p = self::storeUuEncAt(
            $context,
            $bufPtr,
            $p,
            $context->builder->select(
                $context->builder->icmp(Builder::INT_SGT, $remain, $one),
                $context->builder->trunc($context->builder->or(
                    $context->builder->and($context->builder->shl($b1, $i64->constInt(2, false)), $i64->constInt(074, false)),
                    $context->builder->and($context->builder->lShr($b2, $i64->constInt(6, false)), $i64->constInt(03, false))
                ), $i8),
                self::uuEncChar($context, $i8->constInt(0, false))
            )
        );
        $p = self::storeUuEncAt(
            $context,
            $bufPtr,
            $p,
            $context->builder->select(
                $context->builder->icmp(Builder::INT_SGT, $remain, $two),
                self::uuEncChar($context, $context->builder->trunc($context->builder->and($b2, $i64->constInt(077, false)), $i8)),
                self::uuEncChar($context, $i8->constInt(0, false))
            )
        );
        $context->builder->store($p, $pSlot);
        $context->builder->branch($tailDone);

        $context->builder->positionAtEnd($tailDone);
        $len = $context->builder->load($lenSlot);
        $finalNl = $fn->appendBasicBlock('uu_enc_final_nl');
        $finalPad = $fn->appendBasicBlock('uu_enc_final_pad');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $len, $fortyFive),
            $finalNl,
            $finalPad
        );
        $context->builder->positionAtEnd($finalNl);
        $p = $context->builder->load($pSlot);
        $context->builder->store(self::storeByteAt($context, $bufPtr, $p, $i8->constInt(10, false)), $pSlot);
        $context->builder->branch($finalPad);
        $context->builder->positionAtEnd($finalPad);
        $p = $context->builder->load($pSlot);
        $p = self::storeUuEncAt($context, $bufPtr, $p, $i8->constInt(0, false));
        $p = self::storeByteAt($context, $bufPtr, $p, $i8->constInt(10, false));
        $ret = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $p,
            $bufPtr
        );
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($ret);
    }

    public static function implementDecodeBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('uu_dec_entry');
        $context->builder->positionAtEnd($entry);

        $src = $fn->getParam(0);
        $out = $fn->getParam(1);
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $four = $i64->constInt(4, false);
        $fortyFive = $i64->constInt(45, false);
        $sixty = $i64->constInt(60, false);

        $srcLen = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcData = $context->builder->structGep($src, $map['value']);

        $emptyFail = $fn->appendBasicBlock('uu_dec_empty_fail');
        $allocBb = $fn->appendBasicBlock('uu_dec_alloc');
        $failBb = $fn->appendBasicBlock('uu_dec_fail');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $srcLen, $zero),
            $emptyFail,
            $allocBb
        );

        $context->builder->positionAtEnd($emptyFail);
        self::emitDecodeFail($context, $out);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($allocBb);
        $cap = $context->builder->add($srcLen, $context->builder->unsignedDiv($srcLen, $i64->constInt(4, false)));
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($cap, $sizeT)
        );
        $workBb = $fn->appendBasicBlock('uu_dec_work');
        $allocFail = $fn->appendBasicBlock('uu_dec_alloc_fail');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $buf, $i8p->constNull()),
            $allocFail,
            $workBb
        );
        $context->builder->positionAtEnd($allocFail);
        self::emitDecodeFail($context, $out);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($workBb);
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $sSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $pSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $totalSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $chunkLenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $eeSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero, $sSlot);
        $context->builder->store($zero, $pSlot);
        $context->builder->store($zero, $totalSlot);

        $outerHead = $fn->appendBasicBlock('uu_dec_outer_head');
        $outerBody = $fn->appendBasicBlock('uu_dec_outer_body');
        $outerDone = $fn->appendBasicBlock('uu_dec_outer_done');
        $context->builder->branch($outerHead);

        $context->builder->positionAtEnd($outerHead);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $context->builder->load($sSlot), $srcLen),
            $outerBody,
            $outerDone
        );

        $context->builder->positionAtEnd($outerBody);
        $s = $context->builder->load($sSlot);
        $chunkLen = $context->builder->zExt(
            self::uuDecChar($context, $context->builder->load($context->builder->gep($srcData, $s))),
            $i64
        );
        $context->builder->store($context->builder->addNoSignedWrap($s, $one), $sSlot);
        $zeroBreak = $fn->appendBasicBlock('uu_dec_zero_break');
        $lenCheck = $fn->appendBasicBlock('uu_dec_len_check');
        $eeCalc = $fn->appendBasicBlock('uu_dec_ee_calc');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $chunkLen, $zero),
            $zeroBreak,
            $lenCheck
        );

        $context->builder->positionAtEnd($lenCheck);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $chunkLen, $srcLen),
            $failBb,
            $eeCalc
        );
        $context->builder->positionAtEnd($eeCalc);
        $context->builder->store($chunkLen, $chunkLenSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($totalSlot), $chunkLen),
            $totalSlot
        );
        $s = $context->builder->load($sSlot);
        $eeOffset = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $chunkLen, $fortyFive),
            $sixty,
            $context->builder->unsignedDiv(
                $context->builder->mul($chunkLen, $i64->constInt(133, false)),
                $i64->constInt(100, false)
            )
        );
        $ee = $context->builder->addNoSignedWrap($s, $eeOffset);
        $context->builder->store($ee, $eeSlot);
        $innerBb = $fn->appendBasicBlock('uu_dec_inner');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $ee, $srcLen),
            $failBb,
            $innerBb
        );

        $context->builder->positionAtEnd($innerBb);
        $innerHead = $fn->appendBasicBlock('uu_dec_inner_head');
        $innerBody = $fn->appendBasicBlock('uu_dec_inner_body');
        $innerDone = $fn->appendBasicBlock('uu_dec_inner_done');
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerHead);
        $s = $context->builder->load($sSlot);
        $ee = $context->builder->load($eeSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $s, $ee),
            $innerBody,
            $innerDone
        );

        $context->builder->positionAtEnd($innerBody);
        $s = $context->builder->load($sSlot);
        $quadBb = $fn->appendBasicBlock('uu_dec_quad');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $context->builder->addNoSignedWrap($s, $four), $srcLen),
            $failBb,
            $quadBb
        );
        $context->builder->positionAtEnd($quadBb);
        $p = $context->builder->load($pSlot);
        $c0 = self::uuDecChar($context, $context->builder->load($context->builder->gep($srcData, $s)));
        $c1 = self::uuDecChar($context, $context->builder->load($context->builder->gep($srcData, $context->builder->addNoSignedWrap($s, $one))));
        $c2 = self::uuDecChar($context, $context->builder->load($context->builder->gep($srcData, $context->builder->addNoSignedWrap($s, $two))));
        $c3 = self::uuDecChar($context, $context->builder->load($context->builder->gep($srcData, $context->builder->addNoSignedWrap($s, $three))));
        $p = self::storeByteAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->or(
            $context->builder->shl($context->builder->zExt($c0, $i64), $i64->constInt(2, false)),
            $context->builder->lShr($context->builder->zExt($c1, $i64), $i64->constInt(4, false))
        ), $i8));
        $p = self::storeByteAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->or(
            $context->builder->shl($context->builder->zExt($c1, $i64), $i64->constInt(4, false)),
            $context->builder->lShr($context->builder->zExt($c2, $i64), $i64->constInt(2, false))
        ), $i8));
        $p = self::storeByteAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->or(
            $context->builder->shl($context->builder->zExt($c2, $i64), $i64->constInt(6, false)),
            $context->builder->zExt($c3, $i64)
        ), $i8));
        $context->builder->store($p, $pSlot);
        $context->builder->store($context->builder->addNoSignedWrap($s, $four), $sSlot);
        $context->builder->branch($innerHead);

        $context->builder->positionAtEnd($innerDone);
        $chunkLen = $context->builder->load($chunkLenSlot);
        $skipNl = $fn->appendBasicBlock('uu_dec_skip_nl');
        $shortBreak = $fn->appendBasicBlock('uu_dec_short_break');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $chunkLen, $fortyFive),
            $shortBreak,
            $skipNl
        );
        $context->builder->positionAtEnd($skipNl);
        $context->builder->store(
            $context->builder->addNoSignedWrap($context->builder->load($sSlot), $one),
            $sSlot
        );
        $context->builder->branch($outerHead);
        $context->builder->positionAtEnd($shortBreak);
        $context->builder->branch($zeroBreak);

        $context->builder->positionAtEnd($zeroBreak);
        $context->builder->branch($outerDone);

        $context->builder->positionAtEnd($outerDone);
        $total = $context->builder->load($totalSlot);
        $written = $context->builder->load($pSlot);
        $padBb = $fn->appendBasicBlock('uu_dec_pad');
        $successBb = $fn->appendBasicBlock('uu_dec_success');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $written, $total),
            $padBb,
            $successBb
        );

        $context->builder->positionAtEnd($padBb);
        $s = $context->builder->load($sSlot);
        $padLen = $context->builder->sub($total, $written);
        $padBody = $fn->appendBasicBlock('uu_dec_pad_body');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $padLen, $zero),
            $padBody,
            $successBb
        );
        $context->builder->positionAtEnd($padBody);
        $c0 = self::uuDecChar($context, $context->builder->load($context->builder->gep($srcData, $s)));
        $c1 = self::uuDecChar($context, $context->builder->load($context->builder->gep($srcData, $context->builder->addNoSignedWrap($s, $one))));
        $p = $context->builder->load($pSlot);
        $p = self::storeByteAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->or(
            $context->builder->shl($context->builder->zExt($c0, $i64), $i64->constInt(2, false)),
            $context->builder->lShr($context->builder->zExt($c1, $i64), $i64->constInt(4, false))
        ), $i8));
        $padMore = $fn->appendBasicBlock('uu_dec_pad_more');
        $context->builder->store($p, $pSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $padLen, $one),
            $padMore,
            $successBb
        );
        $context->builder->positionAtEnd($padMore);
        $c2 = self::uuDecChar($context, $context->builder->load($context->builder->gep($srcData, $context->builder->addNoSignedWrap($s, $two))));
        $p = $context->builder->load($pSlot);
        $p = self::storeByteAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->or(
            $context->builder->shl($context->builder->zExt($c1, $i64), $i64->constInt(4, false)),
            $context->builder->lShr($context->builder->zExt($c2, $i64), $i64->constInt(2, false))
        ), $i8));
        $padLast = $fn->appendBasicBlock('uu_dec_pad_last');
        $context->builder->store($p, $pSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $padLen, $two),
            $padLast,
            $successBb
        );
        $context->builder->positionAtEnd($padLast);
        $c3 = self::uuDecChar($context, $context->builder->load($context->builder->gep($srcData, $context->builder->addNoSignedWrap($s, $three))));
        $p = $context->builder->load($pSlot);
        $p = self::storeByteAt($context, $bufPtr, $p, $context->builder->trunc($context->builder->or(
            $context->builder->shl($context->builder->zExt($c2, $i64), $i64->constInt(6, false)),
            $context->builder->zExt($c3, $i64)
        ), $i8));
        $context->builder->store($p, $pSlot);
        $context->builder->branch($successBb);

        $context->builder->positionAtEnd($successBb);
        $dest = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->load($totalSlot),
            $bufPtr
        );
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $dest);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($failBb);
        self::emitDecodeFail($context, $out);
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnVoid();
    }

    private static function uuEncChar(Context $context, Value $c): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $isZero = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(0, false));
        $masked = $context->builder->and($c, $i8->constInt(077, false));
        $encoded = $context->builder->add($masked, $i8->constInt(32, false));

        return $context->builder->select($isZero, $i8->constInt(96, false), $encoded);
    }

    private static function uuDecChar(Context $context, Value $c): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->and(
            $context->builder->sub($c, $i8->constInt(32, false)),
            $i8->constInt(077, false)
        );
    }

    private static function storeUuEncAt(Context $context, Value $buf, Value $pos, Value $c): Value
    {
        return self::storeByteAt($context, $buf, $pos, self::uuEncChar($context, $c));
    }

    private static function storeByteAt(Context $context, Value $buf, Value $pos, Value $byte): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store($byte, $context->builder->gep($buf, $pos));

        return $context->builder->addNoSignedWrap($pos, $i64->constInt(1, false));
    }

    private static function emitDecodeFail(Context $context, Value $out): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString(self::UUDEC_ERR_MSG), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $sizeT->constInt(\strlen(self::UUDEC_ERR_MSG), false),
            $i32->constInt(2, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i32->constInt(0, false)
        );
    }
}
