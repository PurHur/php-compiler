<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPCompiler\ext\standard\VmArray;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for {@see \PHPCompiler\VM\HashTable::padCopy()} (#26971).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayPadJitHelper} returns a PHP
 * HashTable that is not a native `__hashtable__` — consumers (implode/foreach/count) see
 * empty/segfault after `c:main_before_php` (peer {@see HashTableReverseLlvm} / #27067,
 * {@see Builtin\RangeIntRuntime} / #26956).
 *
 * VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::pad()} /
 * {@see \PHPCompiler\ext\standard\ArrayPadJitHelper}.
 * php-src: ext/standard/array.c — php_array_pad()
 */
final class HashTablePadLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * Legacy 3-arg array_pad — pad direction is the sign of {@param $length}.
     *
     * @param Value $srcHt  __hashtable__*
     * @param Value $length int64
     * @param Value $valuePtr __value__*
     */
    public static function pad(Context $context, Value $srcHt, Value $length, Value $valuePtr): Value
    {
        $padVar = self::valueVar($context, $valuePtr);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $tag = (string) self::nextSeq();
        $zero64 = $i64->constInt(0, false);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        self::guardPadSize($context, $srcHt, $length, 'array_pad_legacy_'.$tag);

        $negLen = $context->builder->icmp(Builder::INT_SLT, $length, $zero64);
        $negBlock = BasicBlockHelper::append($context, 'ht_pad_abs_neg_'.$tag);
        $posBlock = BasicBlockHelper::append($context, 'ht_pad_abs_pos_'.$tag);
        $absDone = BasicBlockHelper::append($context, 'ht_pad_abs_done_'.$tag);
        $context->builder->branchIf($negLen, $negBlock, $posBlock);
        $context->builder->positionAtEnd($negBlock);
        $negated = $context->builder->sub($zero64, $length);
        $context->builder->branch($absDone);
        $context->builder->positionAtEnd($posBlock);
        $context->builder->branch($absDone);
        $context->builder->positionAtEnd($absDone);
        $absPhi = $context->builder->phi($i64);
        $absPhi->addIncoming($negated, $negBlock);
        $absPhi->addIncoming($length, $posBlock);
        $target = $context->builder->truncOrBitCast($absPhi, $sizeT);

        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $srcHt
        );
        $needsPad = $context->builder->icmp(Builder::INT_SGT, $target, $count);
        $noPadBlock = BasicBlockHelper::append($context, 'ht_pad_no_pad_'.$tag);
        $padBlock = BasicBlockHelper::append($context, 'ht_pad_pad_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'ht_pad_done_'.$tag);
        $context->builder->branchIf($needsPad, $padBlock, $noPadBlock);

        $context->builder->positionAtEnd($noPadBlock);
        $copied = HashTableCowLlvm::duplicate($context, $srcHt);
        $noPadExit = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($padBlock);
        $padCount = $context->builder->sub($target, $count);
        $posLen = $context->builder->icmp(Builder::INT_SGT, $length, $zero64);
        $rightBlock = BasicBlockHelper::append($context, 'ht_pad_right_'.$tag);
        $leftBlock = BasicBlockHelper::append($context, 'ht_pad_left_'.$tag);
        $padDone = BasicBlockHelper::append($context, 'ht_pad_pad_done_'.$tag);
        $context->builder->branchIf($posLen, $rightBlock, $leftBlock);

        $context->builder->positionAtEnd($rightBlock);
        $rightHt = self::padRight($context, $srcHt, $padCount, $padVar, $tag.'_r');
        $rightExit = $context->builder->getInsertBlock();
        $context->builder->branch($padDone);

        $context->builder->positionAtEnd($leftBlock);
        $leftHt = self::padLeft($context, $srcHt, $padCount, $padVar, $tag.'_l');
        $leftExit = $context->builder->getInsertBlock();
        $context->builder->branch($padDone);

        $context->builder->positionAtEnd($padDone);
        $padPhi = $context->builder->phi($rightHt->typeOf());
        $padPhi->addIncoming($rightHt, $rightExit);
        $padPhi->addIncoming($leftHt, $leftExit);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $resultPhi = $context->builder->phi($copied->typeOf());
        $resultPhi->addIncoming($copied, $noPadExit);
        $resultPhi->addIncoming($padPhi, $padDone);

        return $resultPhi;
    }

    /**
     * PHP 8.4+ 4-arg form — absolute {@param $length} with ARRAY_PAD_* mode.
     *
     * @param Value $srcHt   __hashtable__*
     * @param Value $length  int64
     * @param Value $valuePtr __value__*
     * @param Value $padType int64
     */
    public static function padWithType(
        Context $context,
        Value $srcHt,
        Value $length,
        Value $valuePtr,
        Value $padType
    ): Value {
        $padVar = self::valueVar($context, $valuePtr);
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $tag = (string) self::nextSeq();
        $zero64 = $i64->constInt(0, false);
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $two = $sizeT->constInt(2, false);

        self::guardPadSize($context, $srcHt, $length, 'array_pad_typed_'.$tag);

        $negLen = $context->builder->icmp(Builder::INT_SLT, $length, $zero64);
        $negBlock = BasicBlockHelper::append($context, 'ht_pad_type_abs_neg_'.$tag);
        $posBlock = BasicBlockHelper::append($context, 'ht_pad_type_abs_pos_'.$tag);
        $absDone = BasicBlockHelper::append($context, 'ht_pad_type_abs_done_'.$tag);
        $context->builder->branchIf($negLen, $negBlock, $posBlock);
        $context->builder->positionAtEnd($negBlock);
        $negated = $context->builder->sub($zero64, $length);
        $context->builder->branch($absDone);
        $context->builder->positionAtEnd($posBlock);
        $context->builder->branch($absDone);
        $context->builder->positionAtEnd($absDone);
        $absPhi = $context->builder->phi($i64);
        $absPhi->addIncoming($negated, $negBlock);
        $absPhi->addIncoming($length, $posBlock);
        $target = $context->builder->truncOrBitCast($absPhi, $sizeT);

        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $srcHt
        );
        $needsPad = $context->builder->icmp(Builder::INT_SGT, $target, $count);
        $noPadBlock = BasicBlockHelper::append($context, 'ht_pad_type_no_pad_'.$tag);
        $padBlock = BasicBlockHelper::append($context, 'ht_pad_type_pad_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'ht_pad_type_done_'.$tag);
        $context->builder->branchIf($needsPad, $padBlock, $noPadBlock);

        $context->builder->positionAtEnd($noPadBlock);
        $copied = HashTableCowLlvm::duplicate($context, $srcHt);
        $noPadExit = $context->builder->getInsertBlock();
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($padBlock);
        $padCount = $context->builder->sub($target, $count);
        $bothConst = $i64->constInt(StdlibConstants::ARRAY_PAD_BOTH, false);
        $leftConst = $i64->constInt(StdlibConstants::ARRAY_PAD_LEFT, false);
        $isBoth = $context->builder->icmp(Builder::INT_EQ, $padType, $bothConst);
        $isLeft = $context->builder->icmp(Builder::INT_EQ, $padType, $leftConst);
        $bothBlock = BasicBlockHelper::append($context, 'ht_pad_type_both_'.$tag);
        $leftBlock = BasicBlockHelper::append($context, 'ht_pad_type_left_'.$tag);
        $rightBlock = BasicBlockHelper::append($context, 'ht_pad_type_right_'.$tag);
        $dispatchBlock = BasicBlockHelper::append($context, 'ht_pad_type_dispatch_'.$tag);
        $typeDone = BasicBlockHelper::append($context, 'ht_pad_type_mode_done_'.$tag);
        $context->builder->branchIf($isBoth, $bothBlock, $dispatchBlock);
        $context->builder->positionAtEnd($dispatchBlock);
        $context->builder->branchIf($isLeft, $leftBlock, $rightBlock);

        $context->builder->positionAtEnd($rightBlock);
        $rightHt = self::padRight($context, $srcHt, $padCount, $padVar, $tag.'_tr');
        $rightExit = $context->builder->getInsertBlock();
        $context->builder->branch($typeDone);

        $context->builder->positionAtEnd($leftBlock);
        $leftHt = self::padLeft($context, $srcHt, $padCount, $padVar, $tag.'_tl');
        $leftExit = $context->builder->getInsertBlock();
        $context->builder->branch($typeDone);

        $context->builder->positionAtEnd($bothBlock);
        $leftCount = $context->builder->unsignedDiv(
            $context->builder->add($padCount, $one),
            $two
        );
        $rightCount = $context->builder->sub($padCount, $leftCount);
        $bothLeft = self::padLeft($context, $srcHt, $leftCount, $padVar, $tag.'_tbl');
        $bothHt = self::appendPadTimes($context, $bothLeft, $rightCount, $padVar, $tag.'_tbr');
        $bothExit = $context->builder->getInsertBlock();
        $context->builder->branch($typeDone);

        $context->builder->positionAtEnd($typeDone);
        $typePhi = $context->builder->phi($rightHt->typeOf());
        $typePhi->addIncoming($rightHt, $rightExit);
        $typePhi->addIncoming($leftHt, $leftExit);
        $typePhi->addIncoming($bothHt, $bothExit);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);
        $resultPhi = $context->builder->phi($copied->typeOf());
        $resultPhi->addIncoming($copied, $noPadExit);
        $resultPhi->addIncoming($typePhi, $typeDone);

        return $resultPhi;
    }

    private static function valueVar(Context $context, Value $valuePtr): Variable
    {
        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valuePtr);
    }

    /** Zend pad-size guard before allocating (#26658). */
    private static function guardPadSize(Context $context, Value $srcHt, Value $length, string $prefix): void
    {
        TypeErrorRaise::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $zero64 = $i64->constInt(0, false);
        $maxPad = $i64->constInt(VmArray::ARRAY_PAD_MAX_PAD_SIZE, false);
        $intMin = $i64->constInt(\PHP_INT_MIN, true);

        $notIntMin = $context->builder->icmp(Builder::INT_NE, $length, $intMin);
        // Zend 8.4 wording (#29342); numeric guard still ARRAY_PAD_MAX_PAD_SIZE (#26658).
        $zendMsg = 'array_pad(): Argument #2 ($length) must not exceed the maximum allowed array size';
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $notIntMin,
            $prefix.'_intmin',
            $zendMsg
        );

        $negLen = $context->builder->icmp(Builder::INT_SLT, $length, $zero64);
        $absLen = $context->builder->select(
            $negLen,
            $context->builder->sub($zero64, $length),
            $length
        );
        $count = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $srcHt
        );
        $countI64 = JitNestedHelperCoerce::scalarToI64($context, $count, $sizeT);
        $padAmount = $context->builder->sub($absLen, $countI64);
        // When target <= count, padAmount <= 0 — always OK.
        $within = $context->builder->icmp(Builder::INT_SLE, $padAmount, $maxPad);
        TypeErrorRaise::emitBranchOrAbortOnValueErrorFailure(
            $context,
            $within,
            $prefix.'_padmax',
            $zendMsg
        );
    }

    private static function padRight(
        Context $context,
        Value $srcHt,
        Value $padCount,
        Variable $padVar,
        string $tag
    ): Value {
        $dest = HashTableCowLlvm::duplicate($context, $srcHt);

        return self::appendPadTimes($context, $dest, $padCount, $padVar, $tag);
    }

    private static function appendPadTimes(
        Context $context,
        Value $dest,
        Value $padCount,
        Variable $padVar,
        string $tag
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'ht_pad_append_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_pad_append_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_pad_append_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_pad_append_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $padCount);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        ArrayBuiltinHelper::appendElement($context, $dest, $padVar);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $dest;
    }

    /**
     * Left-pad: pad slots at 0..padCount-1, then src packed indices shifted, string keys preserved.
     */
    private static function padLeft(
        Context $context,
        Value $srcHt,
        Value $padCount,
        Variable $padVar,
        string $tag
    ): Value {
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $dest = HashTableHelper::alloc($context);

        $padIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $padIdxSlot);
        $padHead = BasicBlockHelper::append($context, 'ht_pad_left_pad_head_'.$tag);
        $padBody = BasicBlockHelper::append($context, 'ht_pad_left_pad_body_'.$tag);
        $padAdvance = BasicBlockHelper::append($context, 'ht_pad_left_pad_advance_'.$tag);
        $copyBlock = BasicBlockHelper::append($context, 'ht_pad_left_copy_'.$tag);
        $context->builder->branch($padHead);

        $context->builder->positionAtEnd($padHead);
        $padIdx = $context->builder->load($padIdxSlot);
        $padAtEnd = $context->builder->icmp(Builder::INT_SGE, $padIdx, $padCount);
        $context->builder->branchIf($padAtEnd, $copyBlock, $padBody);

        $context->builder->positionAtEnd($padBody);
        HashTableHelper::setAtIndex($context, $dest, $padIdx, $padVar);
        $context->builder->branch($padAdvance);

        $context->builder->positionAtEnd($padAdvance);
        $context->builder->store($context->builder->addNoSignedWrap($padIdx, $one), $padIdxSlot);
        $context->builder->branch($padHead);

        $context->builder->positionAtEnd($copyBlock);
        self::copyPackedShifted($context, $dest, $srcHt, $padCount, $tag);
        self::copyStringKeys($context, $dest, $srcHt, $tag);

        // nextFreeElement must cover pad + packed span (assoc string keys sit beside packed).
        $srcNext = $context->builder->load(
            $context->builder->structGep($srcHt, $context->structFieldMap['__hashtable__']['nextFreeElement'])
        );
        $map = $context->structFieldMap['__hashtable__'];
        $context->builder->store(
            $context->builder->addNoSignedWrap($padCount, $srcNext),
            $context->builder->structGep($dest, $map['nextFreeElement'])
        );

        return $dest;
    }

    private static function copyPackedShifted(
        Context $context,
        Value $dest,
        Value $srcHt,
        Value $destOffset,
        string $tag
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $count = $context->builder->load($context->builder->structGep($srcHt, $map['nextFreeElement']));
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);
        $head = BasicBlockHelper::append($context, 'ht_pad_shift_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_pad_shift_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_pad_shift_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_pad_shift_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $count);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $srcHt,
            $idx
        );
        $skip = BasicBlockHelper::append($context, 'ht_pad_shift_skip_'.$tag);
        $copy = BasicBlockHelper::append($context, 'ht_pad_shift_copy_'.$tag);
        $context->builder->branchIf($isSet, $copy, $skip);

        $context->builder->positionAtEnd($copy);
        $elem = HashTableReadLlvm::readIndexedToValueBox($context, $srcHt, $idx);
        $destIdx = $context->builder->addNoSignedWrap($destOffset, $idx);
        HashTableWriteLlvm::setAtIndex($context, $dest, $destIdx, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($skip);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function copyStringKeys(Context $context, Value $dest, Value $srcHt, string $tag): void
    {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $head = BasicBlockHelper::append($context, 'ht_pad_str_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_pad_str_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_pad_str_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_pad_str_done_'.$tag);
        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrType);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($srcHt, $map['strKeys'])),
            $nodeSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valField);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $keyStr, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store(
            $context->builder->load($context->builder->structGep($node, $nodeMap['next'])),
            $nodeSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }
}
