<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for base64_encode() — RFC 4648 standard alphabet.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitBase64Encode
{
    public static function encode(Context $context, Value $strPtr): Value
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
        $eq = $i8->constInt(61, false);

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
        $context->builder->store(
            $outLen,
            $context->builder->structGep($dest, $destMap['length'])
        );
        $destPtr = $context->builder->structGep($dest, $destMap['value']);

        $alphabet = $context->builder->pointerCast(
            $context->constantFromString('ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/'),
            $context->getTypeFromString('char*')
        );

        $idxSlot = $context->builder->alloca($i64, 1, 'b64enc_idx');
        $outSlot = $context->builder->alloca($i64, 1, 'b64enc_out');
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($zero, $outSlot);

        $loopHead = BasicBlockHelper::append($context, 'b64enc_head');
        $loopBody = BasicBlockHelper::append($context, 'b64enc_body');
        $loopDone = BasicBlockHelper::append($context, 'b64enc_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->icmp(Builder::INT_SGE, $idx, $len);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $idxPlus1 = $context->builder->addNoSignedWrap($idx, $one);
        $idxPlus2 = $context->builder->addNoSignedWrap($idx, $two);
        $hasB1 = $context->builder->icmp(Builder::INT_SLT, $idxPlus1, $len);
        $hasB2 = $context->builder->icmp(Builder::INT_SLT, $idxPlus2, $len);

        $b0 = $context->builder->zExt(
            $context->builder->load($context->builder->gep($charPtr, $idx)),
            $i32
        );
        $b1Block = BasicBlockHelper::append($context, 'b64enc_b1');
        $b1ZeroBlock = BasicBlockHelper::append($context, 'b64enc_b1_zero');
        $b1Merge = BasicBlockHelper::append($context, 'b64enc_b1_merge');
        $context->builder->branchIf($hasB1, $b1Block, $b1ZeroBlock);
        $context->builder->positionAtEnd($b1Block);
        $b1Val = $context->builder->zExt(
            $context->builder->load($context->builder->gep($charPtr, $idxPlus1)),
            $i32
        );
        $context->builder->branch($b1Merge);
        $context->builder->positionAtEnd($b1ZeroBlock);
        $context->builder->branch($b1Merge);
        $context->builder->positionAtEnd($b1Merge);
        $b1 = $context->builder->phi($i32);
        $b1->addIncoming($b1Val, $b1Block);
        $b1->addIncoming($i32->constInt(0, false), $b1ZeroBlock);

        $b2Block = BasicBlockHelper::append($context, 'b64enc_b2');
        $b2ZeroBlock = BasicBlockHelper::append($context, 'b64enc_b2_zero');
        $b2Merge = BasicBlockHelper::append($context, 'b64enc_b2_merge');
        $context->builder->branchIf($hasB2, $b2Block, $b2ZeroBlock);
        $context->builder->positionAtEnd($b2Block);
        $b2Val = $context->builder->zExt(
            $context->builder->load($context->builder->gep($charPtr, $idxPlus2)),
            $i32
        );
        $context->builder->branch($b2Merge);
        $context->builder->positionAtEnd($b2ZeroBlock);
        $context->builder->branch($b2Merge);
        $context->builder->positionAtEnd($b2Merge);
        $b2 = $context->builder->phi($i32);
        $b2->addIncoming($b2Val, $b2Block);
        $b2->addIncoming($i32->constInt(0, false), $b2ZeroBlock);

        $n = $context->builder->or(
            $context->builder->shl($b0, $i32->constInt(16, false)),
            $context->builder->or(
                $context->builder->shl($b1, $i32->constInt(8, false)),
                $b2
            )
        );

        $outPos = $context->builder->load($outSlot);
        $c0 = $context->builder->lShr($n, $i32->constInt(18, false));
        $c1 = $context->builder->bitwiseAnd(
            $context->builder->lShr($n, $i32->constInt(12, false)),
            $i32->constInt(63, false)
        );
        $c2Raw = $context->builder->bitwiseAnd(
            $context->builder->lShr($n, $i32->constInt(6, false)),
            $i32->constInt(63, false)
        );
        $c3Raw = $context->builder->bitwiseAnd($n, $i32->constInt(63, false));

        $context->builder->store(
            $context->builder->load($context->builder->gep($alphabet, $c0)),
            $context->builder->gep($destPtr, $outPos)
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($alphabet, $c1)),
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPos, $one))
        );

        $c2PadBlock = BasicBlockHelper::append($context, 'b64enc_c2_pad');
        $c2EncBlock = BasicBlockHelper::append($context, 'b64enc_c2_enc');
        $c2Merge = BasicBlockHelper::append($context, 'b64enc_c2_merge');
        $context->builder->branchIf($hasB1, $c2EncBlock, $c2PadBlock);
        $context->builder->positionAtEnd($c2PadBlock);
        $context->builder->store($eq, $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPos, $two)));
        $context->builder->branch($c2Merge);
        $context->builder->positionAtEnd($c2EncBlock);
        $context->builder->store(
            $context->builder->load($context->builder->gep($alphabet, $c2Raw)),
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPos, $two))
        );
        $context->builder->branch($c2Merge);

        $c3PadBlock = BasicBlockHelper::append($context, 'b64enc_c3_pad');
        $c3EncBlock = BasicBlockHelper::append($context, 'b64enc_c3_enc');
        $c3Merge = BasicBlockHelper::append($context, 'b64enc_c3_merge');
        $context->builder->positionAtEnd($c2Merge);
        $context->builder->branchIf($hasB2, $c3EncBlock, $c3PadBlock);
        $context->builder->positionAtEnd($c3PadBlock);
        $context->builder->store(
            $eq,
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPos, $three))
        );
        $context->builder->branch($c3Merge);
        $context->builder->positionAtEnd($c3EncBlock);
        $context->builder->store(
            $context->builder->load($context->builder->gep($alphabet, $c3Raw)),
            $context->builder->gep($destPtr, $context->builder->addNoSignedWrap($outPos, $three))
        );
        $context->builder->branch($c3Merge);

        $context->builder->positionAtEnd($c3Merge);
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $three),
            $idxSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($outPos, $four),
            $outSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($emptyStr, $emptyBlock);
        $result->addIncoming($dest, $loopDone);

        return $result;
    }
}
