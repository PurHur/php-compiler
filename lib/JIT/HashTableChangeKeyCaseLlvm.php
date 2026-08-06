<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\StdlibConstants;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM for {@see \PHPCompiler\ext\standard\VmArray::changeKeyCase()} (#27183).
 *
 * Thin AOT NestedJIT of {@see \PHPCompiler\ext\standard\ArrayChangeKeyCaseJitHelper}
 * segfaults after `c:main_before_php` under `PHP_COMPILER_HELPER_RUNTIME_O=0`
 * (peer {@see HashTableFillKeysLlvm} / #27127, {@see ArrayFlipLlvm} / #26970).
 *
 * Walks packed slots then {@see strKeys} (Zend iterateKeyed order); int keys keep
 * their index; string keys are ASCII-cased on a separated copy. String-key writes
 * mirror {@see HashTableCowLlvm} (value-box points at the node field; separate key).
 *
 * VM SSOT remains {@see \PHPCompiler\ext\standard\VmArray::changeKeyCase()} /
 * {@see \PHPCompiler\ext\standard\ArrayChangeKeyCaseJitHelper}.
 * php-src: ext/standard/array.c — php_array_change_key_case()
 */
final class HashTableChangeKeyCaseLlvm
{
    private static int $seq = 0;

    private static function nextSeq(): int
    {
        return ++self::$seq;
    }

    /**
     * @param Value $srcHt __hashtable__*
     * @param Value $case  int64 — CASE_LOWER (0) vs anything else → upper
     *
     * @return Value __hashtable__*
     */
    public static function changeKeyCase(Context $context, Value $srcHt, Value $case): Value
    {
        $dest = HashTableHelper::alloc($context);
        self::copyPackedEntries($context, $srcHt, $dest);
        self::copyStringEntriesCased($context, $srcHt, $dest, $case);

        return $dest;
    }

    private static function copyPackedEntries(Context $context, Value $src, Value $dest): void
    {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $nextFree = $context->builder->load(
            $context->builder->structGep($src, $htMap['nextFreeElement'])
        );
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ht_ckc_pk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_ckc_pk_body_'.$tag);
        $take = BasicBlockHelper::append($context, 'ht_ckc_pk_take_'.$tag);
        $next = BasicBlockHelper::append($context, 'ht_ckc_pk_next_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_ckc_pk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $take, $next);

        $context->builder->positionAtEnd($take);
        $valVar = HashTableReadLlvm::readIndexedToValueBox($context, $src, $idx);
        HashTableWriteLlvm::setAtIndex($context, $dest, $idx, $valVar);
        $context->builder->branch($next);

        $context->builder->positionAtEnd($next);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function copyStringEntriesCased(
        Context $context,
        Value $src,
        Value $dest,
        Value $case
    ): void {
        $tag = (string) self::nextSeq();
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');

        $nodeSlot = BasicBlockHelper::entryAlloca($context, $nodePtrTy);
        $headNode = $context->builder->load($context->builder->structGep($src, $htMap['strKeys']));
        $context->builder->store($headNode, $nodeSlot);

        $head = BasicBlockHelper::append($context, 'ht_ckc_sk_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ht_ckc_sk_body_'.$tag);
        $advance = BasicBlockHelper::append($context, 'ht_ckc_sk_advance_'.$tag);
        $done = BasicBlockHelper::append($context, 'ht_ckc_sk_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $node = $context->builder->load($nodeSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNull, $done, $body);

        $context->builder->positionAtEnd($body);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $keyStr
        );
        // CASE_LOWER (0) → A..Z +32; else → a..z -32 (VmArray::changeKeyCase).
        $isLower = $context->builder->icmp(
            Builder::INT_EQ,
            $case,
            $i64->constInt(StdlibConstants::CASE_LOWER, false)
        );
        $letterMin = $context->builder->select(
            $isLower,
            $i32->constInt(ord('A'), false),
            $i32->constInt(ord('a'), false)
        );
        $letterMax = $context->builder->select(
            $isLower,
            $i32->constInt(ord('Z'), false),
            $i32->constInt(ord('z'), false)
        );
        $delta = $context->builder->select(
            $isLower,
            $i32->constInt(32, false),
            $i32->constInt(-32, true)
        );
        self::transformAllAsciiDynamic($context, $owned, $letterMin, $letterMax, $delta);
        // Cow-style: TYPE_VALUE Variable over the node value field (not a fresh box copy).
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $elem = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $valField);
        HashTableWriteLlvm::setAtStringKey($context, $dest, $owned, $elem);
        $context->builder->branch($advance);

        $context->builder->positionAtEnd($advance);
        $nextNode = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($nextNode, $nodeSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    /**
     * Like {@see \PHPCompiler\ext\standard\lcfirst::transformAllAscii} but min/max/delta
     * are runtime Values so CASE_LOWER vs UPPER share one CFG (#27183).
     *
     * @param Value $letterMin int32
     * @param Value $letterMax int32
     * @param Value $delta     int32
     */
    private static function transformAllAsciiDynamic(
        Context $context,
        Value $strPtr,
        Value $letterMin,
        Value $letterMax,
        Value $delta
    ): void {
        $tag = (string) self::nextSeq();
        $structName = $strPtr->typeOf()->getElementType()->getName();
        $map = $context->structFieldMap[$structName];
        $len = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $charPtr = $context->builder->structGep($strPtr, $map['value']);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero, $iSlot);

        $done = BasicBlockHelper::append($context, 'ht_ckc_case_done_'.$tag);
        $loopHead = BasicBlockHelper::append($context, 'ht_ckc_case_head_'.$tag);
        $loopBody = BasicBlockHelper::append($context, 'ht_ckc_case_body_'.$tag);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $atChar = $context->builder->gep($charPtr, $i);
        $ch = $context->builder->load($atChar);
        $chI32 = $context->builder->zExt($ch, $i32);
        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI32, $letterMin),
            $context->builder->icmp(Builder::INT_SLE, $chI32, $letterMax)
        );
        $adjusted = $context->builder->add($chI32, $delta);
        $newCh = $context->builder->truncOrBitCast(
            $context->builder->select($inRange, $adjusted, $chI32),
            $ch->typeOf()
        );
        $context->builder->store($newCh, $atChar);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
    }
}
