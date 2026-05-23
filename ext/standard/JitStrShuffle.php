<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helpers for str_shuffle() (in-place Fisher–Yates on a mutable copy).
 */
final class JitStrShuffle
{
    public static function shuffle(Context $context, Value $str): Value
    {
        $structName = $str->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($str, $map['length'])
        );
        $charPtr = $context->builder->structGep($str, $map['value']);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $eight = $i64->constInt(8, false);

        $lenLtTwo = $context->builder->icmp(Builder::INT_SLT, $len, $two);
        $shortBlock = BasicBlockHelper::append($context, 'str_shuffle_short');
        $workBlock = BasicBlockHelper::append($context, 'str_shuffle_work');
        $doneBlock = BasicBlockHelper::append($context, 'str_shuffle_done');
        $context->builder->branchIf($lenLtTwo, $shortBlock, $workBlock);

        $context->builder->positionAtEnd($shortBlock);
        $shortCopy = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $str
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($workBlock);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $len);
        $destMap = $context->structFieldMap['__string__'];
        $context->builder->store(
            $len,
            $context->builder->structGep($dest, $destMap['length'])
        );
        $destPtr = $context->builder->structGep($dest, $destMap['value']);

        $idxSlot = $context->builder->alloca($i64, 1, 'str_shuffle_i');
        $context->builder->store($zero, $idxSlot);

        $copyHead = BasicBlockHelper::append($context, 'str_shuffle_copy_head');
        $copyBody = BasicBlockHelper::append($context, 'str_shuffle_copy_body');
        $copyDone = BasicBlockHelper::append($context, 'str_shuffle_copy_done');
        $context->builder->branch($copyHead);

        $context->builder->positionAtEnd($copyHead);
        $copyIdx = $context->builder->load($idxSlot);
        $copyStop = $context->builder->icmp(Builder::INT_SGE, $copyIdx, $len);
        $context->builder->branchIf($copyStop, $copyDone, $copyBody);

        $context->builder->positionAtEnd($copyBody);
        $srcAt = $context->builder->gep($charPtr, $copyIdx);
        $ch = $context->builder->load($srcAt);
        $destAt = $context->builder->gep($destPtr, $copyIdx);
        $context->builder->store($ch, $destAt);
        $context->builder->store(
            $context->builder->addNoSignedWrap($copyIdx, $one),
            $idxSlot
        );
        $context->builder->branch($copyHead);

        $context->builder->positionAtEnd($copyDone);
        $iSlot = $context->builder->alloca($i64, 1, 'str_shuffle_outer');
        $last = $context->builder->sub($len, $one);
        $context->builder->store($last, $iSlot);

        $loopHead = BasicBlockHelper::append($context, 'str_shuffle_head');
        $loopBody = BasicBlockHelper::append($context, 'str_shuffle_body');
        $loopDone = BasicBlockHelper::append($context, 'str_shuffle_loop_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $iVal = $context->builder->load($iSlot);
        $stop = $context->builder->icmp(Builder::INT_SLE, $iVal, $zero);
        $context->builder->branchIf($stop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $jVal = self::randomIndex($context, $context->builder->add($iVal, $one));
        $iAt = $context->builder->gep($destPtr, $iVal);
        $jAt = $context->builder->gep($destPtr, $jVal);
        $iCh = $context->builder->load($iAt);
        $jCh = $context->builder->load($jAt);
        $context->builder->store($jCh, $iAt);
        $context->builder->store($iCh, $jAt);
        $context->builder->store(
            $context->builder->subNoSignedWrap($iVal, $one),
            $iSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $result = $context->builder->phi($dest->typeOf());
        $result->addIncoming($shortCopy, $shortBlock);
        $result->addIncoming($dest, $loopDone);

        return $result;
    }

    /** Uniform index in [0, $upperExclusive) using 8 CSPRNG bytes. */
    private static function randomIndex(Context $context, Value $upperExclusive): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $randStr = JitRandomBytes::generate($context, $i64->constInt(8, false));
        $randMap = $context->structFieldMap['__string__'];
        $randPtr = $context->builder->structGep($randStr, $randMap['value']);
        $accSlot = $context->builder->alloca($i64, 1, 'str_shuffle_rand');
        $context->builder->store($i64->constInt(0, false), $accSlot);
        $byteSlot = $context->builder->alloca($i64, 1, 'str_shuffle_byte_i');
        $context->builder->store($i64->constInt(0, false), $byteSlot);

        $byteHead = BasicBlockHelper::append($context, 'str_shuffle_rand_head');
        $byteBody = BasicBlockHelper::append($context, 'str_shuffle_rand_body');
        $byteDone = BasicBlockHelper::append($context, 'str_shuffle_rand_done');
        $context->builder->branch($byteHead);

        $context->builder->positionAtEnd($byteHead);
        $bi = $context->builder->load($byteSlot);
        $byteStop = $context->builder->icmp(
            Builder::INT_SGE,
            $bi,
            $i64->constInt(8, false)
        );
        $context->builder->branchIf($byteStop, $byteDone, $byteBody);

        $context->builder->positionAtEnd($byteBody);
        $acc = $context->builder->load($accSlot);
        $byte = $context->builder->zext(
            $context->builder->load($context->builder->gep($randPtr, $bi)),
            $i64
        );
        $shifted = $context->builder->shl($acc, $i64->constInt(8, false));
        $context->builder->store($context->builder->or($shifted, $byte), $accSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($bi, $i64->constInt(1, false)),
            $byteSlot
        );
        $context->builder->branch($byteHead);

        $context->builder->positionAtEnd($byteDone);
        $accVal = $context->builder->load($accSlot);

        return $context->builder->unsigendRem($accVal, $upperExclusive);
    }
}
