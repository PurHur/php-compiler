<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for base64_encode() — byte string to RFC 4648 base64 (PHP-compatible).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitBase64Encode
{
    public static function convert(Context $context, Value $strPtr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $four = $i64->constInt(4, false);

        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $emptyBlock = BasicBlockHelper::append($context, 'b64enc_empty');
        $workBlock = BasicBlockHelper::append($context, 'b64enc_work');
        $doneBlock = BasicBlockHelper::append($context, 'b64enc_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $workBlock);

        $context->builder->positionAtEnd($emptyBlock);
        $emptyStr = $context->builder->call($context->lookupFunction('__string__alloc'), $zero);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $lenPlus2 = $context->builder->addNoSignedWrap($len, $two);
        $groups = $context->builder->unsignedDiv($lenPlus2, $three);
        $outLen = $context->builder->mulNoSignedWrap($groups, $four);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $destMap = $context->structFieldMap['__string__'];
        $context->builder->store($outLen, $context->builder->structGep($dest, $destMap['length']));
        $destPtr = $context->builder->structGep($dest, $destMap['value']);

        $b64Table = $context->builder->pointerCast(
            $context->constantFromString('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'),
            $context->getTypeFromString('char*')
        );
        $pad = $i8->constInt(ord('='), false);

        $idxSlot = $context->builder->alloca($i64, 1, 'b64enc_idx');
        $outSlot = $context->builder->alloca($i64, 1, 'b64enc_out');
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $outSlot);

        $mainHead = BasicBlockHelper::append($context, 'b64enc_main_head');
        $mainBody = BasicBlockHelper::append($context, 'b64enc_main_body');
        $mainDone = BasicBlockHelper::append($context, 'b64enc_main_done');
        $context->builder->branch($mainHead);

        $context->builder->positionAtEnd($mainHead);
        $idx = $context->builder->load($idxSlot);
        $remain = $context->builder->subNoSignedWrap($len, $idx);
        $stop = $context->builder->icmp(Builder::INT_SLE, $remain, $two);
        $context->builder->branchIf($stop, $mainDone, $mainBody);

        $context->builder->positionAtEnd($mainBody);
        $b0 = $context->builder->load($context->builder->gep($charPtr, $idx));
        $b1 = $context->builder->load($context->builder->gep($charPtr, $context->builder->addNoSignedWrap($idx, $one)));
        $b2 = $context->builder->load($context->builder->gep($charPtr, $context->builder->addNoSignedWrap($idx, $two)));
        $b0i = $context->builder->zExt($b0, $i32);
        $b1i = $context->builder->zExt($b1, $i32);
        $b2i = $context->builder->zExt($b2, $i32);
        $outPos = $context->builder->load($outSlot);
        $context->builder->store(
            $context->builder->load($context->builder->gep($b64Table, $context->builder->lShr($b0i, $i32->constInt(2, false)))),
            $context->builder->gep($destPtr, $outPos)
        );
        $hi = $context->builder->or(
            $context->builder->shl($context->builder->bitwiseAnd($b0i, $i32->constInt(0x03, false)), $i32->constInt(4, false)),
            $context->builder->lShr($b1i, $i32->constInt(4, false))
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($b64Table, $hi)),
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPos, $one))
        );
        $mid = $context->builder->or(
            $context->builder->shl($context->builder->bitwiseAnd($b1i, $i32->constInt(0x0f, false)), $i32->constInt(2, false)),
            $context->builder->lShr($b2i, $i32->constInt(6, false))
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($b64Table, $mid)),
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPos, $two))
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($b64Table, $context->builder->bitwiseAnd($b2i, $i32->constInt(0x3f, false)))),
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPos, $three))
        );
        $context->builder->store($context->builder->addNoSignedWrap($outPos, $four), $outSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $three), $idxSlot);
        $context->builder->branch($mainHead);

        $tailBlock = BasicBlockHelper::append($context, 'b64enc_tail');
        $tailDone = BasicBlockHelper::append($context, 'b64enc_tail_done');
        $context->builder->positionAtEnd($mainDone);
        $idxTail = $context->builder->load($idxSlot);
        $remainTail = $context->builder->subNoSignedWrap($len, $idxTail);
        $hasTail = $context->builder->icmp(Builder::INT_SGT, $remainTail, $zero);
        $context->builder->branchIf($hasTail, $tailBlock, $tailDone);

        $context->builder->positionAtEnd($tailBlock);
        $tb0 = $context->builder->load($context->builder->gep($charPtr, $idxTail));
        $tb0i = $context->builder->zExt($tb0, $i32);
        $outPosTail = $context->builder->load($outSlot);
        $context->builder->store(
            $context->builder->load($context->builder->gep($b64Table, $context->builder->lShr($tb0i, $i32->constInt(2, false)))),
            $context->builder->gep($destPtr, $outPosTail)
        );
        $oneRemain = $context->builder->icmp(Builder::INT_EQ, $remainTail, $one);
        $tailOne = BasicBlockHelper::append($context, 'b64enc_tail_one');
        $tailTwo = BasicBlockHelper::append($context, 'b64enc_tail_two');
        $context->builder->branchIf($oneRemain, $tailOne, $tailTwo);

        $context->builder->positionAtEnd($tailOne);
        $context->builder->store(
            $context->builder->load($context->builder->gep(
                $b64Table,
                $context->builder->shl($context->builder->bitwiseAnd($tb0i, $i32->constInt(0x03, false)), $i32->constInt(4, false))
            )),
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPosTail, $one))
        );
        $context->builder->store($pad, $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPosTail, $two)));
        $context->builder->store($pad, $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPosTail, $three)));
        $context->builder->branch($tailDone);

        $context->builder->positionAtEnd($tailTwo);
        $tb1 = $context->builder->load($context->builder->gep($charPtr, $context->builder->addNoSignedWrap($idxTail, $one)));
        $tb1i = $context->builder->zExt($tb1, $i32);
        $context->builder->store(
            $context->builder->load($context->builder->gep(
                $b64Table,
                $context->builder->or(
                    $context->builder->shl($context->builder->bitwiseAnd($tb0i, $i32->constInt(0x03, false)), $i32->constInt(4, false)),
                    $context->builder->lShr($tb1i, $i32->constInt(4, false))
                )
            )),
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPosTail, $one))
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep(
                $b64Table,
                $context->builder->shl($context->builder->bitwiseAnd($tb1i, $i32->constInt(0x0f, false)), $i32->constInt(2, false))
            )),
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPosTail, $two))
        );
        $context->builder->store($pad, $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPosTail, $three)));
        $context->builder->branch($tailDone);

        $context->builder->positionAtEnd($tailDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $tailDone);

        return $result;
    }
}
