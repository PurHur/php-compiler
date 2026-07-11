<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\ClosureWithCaptures;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT lowering for user-comparator array diff/intersect builtins (php-src ext/standard/array.c; #9155).
 *
 * VM SSOT: {@see \PHPCompiler\ext\standard\VmArrayUserSetOps}.
 */
final class JitArrayUserSetOps
{
    public static function arrayUdiff(
        Context $context,
        Variable $callback,
        Variable $first,
        Variable ...$others
    ): Value {
        return self::arrayValueOp($context, false, $callback, $first, ...$others);
    }

    public static function arrayUintersect(
        Context $context,
        Variable $callback,
        Variable $first,
        Variable ...$others
    ): Value {
        return self::arrayValueOp($context, true, $callback, $first, ...$others);
    }

    public static function arrayDiffUkey(
        Context $context,
        Variable $callback,
        Variable $first,
        Variable ...$others
    ): Value {
        return self::arrayKeyOp($context, false, $callback, $first, ...$others);
    }

    /**
     * @param list<Variable> $others
     *
     * @return list<Value>
     */
    private static function loadOtherHashTables(Context $context, array $others): array
    {
        $loaded = [];
        foreach ($others as $other) {
            $loaded[] = ArrayBuiltinHelper::loadHashTable($context, $other);
        }

        return $loaded;
    }

    private static function arrayValueOp(
        Context $context,
        bool $intersect,
        Variable $callback,
        Variable $first,
        Variable ...$others
    ): Value {
        [$closureCall, $returnTypeTag] = self::resolveCompareCallback($context, $callback);
        $src = ArrayBuiltinHelper::loadHashTable($context, $first);
        $otherHts = self::loadOtherHashTables($context, $others);

        return self::filterFirstHashTableByValueCompare(
            $context,
            $src,
            $otherHts,
            $intersect,
            $closureCall,
            $returnTypeTag
        );
    }

    private static function arrayKeyOp(
        Context $context,
        bool $intersect,
        Variable $callback,
        Variable $first,
        Variable ...$others
    ): Value {
        if ([] === $others) {
            throw new \ArgumentCountError('array_diff_ukey() expects at least 3 arguments, 2 given');
        }
        [$closureCall, $returnTypeTag] = self::resolveCompareCallback($context, $callback);
        $src = ArrayBuiltinHelper::loadHashTable($context, $first);
        $otherHts = self::loadOtherHashTables($context, $others);

        return self::filterFirstHashTableByKeyCompare(
            $context,
            $src,
            $otherHts,
            $intersect,
            $closureCall,
            $returnTypeTag
        );
    }

    /**
     * @return array{0: Call, 1: string}
     */
    private static function resolveCompareCallback(Context $context, Variable $callback): array
    {
        if (UsortCallbackPolicy::isClosureJitLowerable($callback)) {
            $closureCall = $callback->closureCall;
            if (null === $closureCall) {
                throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
            }

            return [$closureCall, self::closureReturnTypeTag($context, $closureCall)];
        }
        throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
    }

    private static function closureReturnTypeTag(Context $context, Call $call): string
    {
        if ($call instanceof ClosureWithCaptures) {
            $call = $call->innerNative();
        }
        if (!$call instanceof Call\Native) {
            throw new \LogicException(
                'array_udiff() closure callback must be a compiled user closure in this build'
            );
        }
        $retTy = $context->functionReturnType[strtolower($call->name)] ?? null;
        if (null === $retTy) {
            throw new \LogicException('array_udiff() closure return type unknown for JIT');
        }

        return $retTy;
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function filterFirstHashTableByValueCompare(
        Context $context,
        Value $src,
        array $otherHts,
        bool $intersect,
        Call $closureCall,
        string $returnTypeTag
    ): Value {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_udiff_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_udiff_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_udiff_packed_body');
        $packedTest = BasicBlockHelper::append($context, 'array_udiff_packed_test');
        $packedKeep = BasicBlockHelper::append($context, 'array_udiff_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_udiff_packed_skip');
        $packedNext = BasicBlockHelper::append($context, 'array_udiff_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_udiff_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedTest, $packedNext);

        $context->builder->positionAtEnd($packedTest);
        $needle = HashTableHelper::readIndexedToValueBox($context, $src, $idx);
        $found = self::valueInAnyOtherWithClosure(
            $context,
            $needle,
            $otherHts,
            $closureCall,
            $returnTypeTag
        );
        $keep = $intersect ? $found : $context->builder->not($found);
        $context->builder->branchIf($keep, $packedKeep, $packedSkip);

        $context->builder->positionAtEnd($packedKeep);
        HashTableHelper::setAtIndex($context, $dest, $idx, $needle);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_udiff_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_udiff_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_udiff_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_udiff_str_body');
        $strTest = BasicBlockHelper::append($context, 'array_udiff_str_test');
        $strKeep = BasicBlockHelper::append($context, 'array_udiff_str_keep');
        $strSkip = BasicBlockHelper::append($context, 'array_udiff_str_skip');
        $strNext = BasicBlockHelper::append($context, 'array_udiff_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_udiff_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $context->builder->branch($strTest);

        $context->builder->positionAtEnd($strTest);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $needle = self::valueEntryToVariable($context, $context->builder->structGep($node, $nodeMap['value']));
        $found = self::valueInAnyOtherWithClosure(
            $context,
            $needle,
            $otherHts,
            $closureCall,
            $returnTypeTag
        );
        $keep = $intersect ? $found : $context->builder->not($found);
        $context->builder->branchIf($keep, $strKeep, $strSkip);

        $context->builder->positionAtEnd($strKeep);
        HashTableHelper::setAtStringKey(
            $context,
            $dest,
            $keyStr,
            $needle
        );
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function filterFirstHashTableByKeyCompare(
        Context $context,
        Value $src,
        array $otherHts,
        bool $intersect,
        Call $closureCall,
        string $returnTypeTag
    ): Value {
        $dest = HashTableHelper::alloc($context);
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');

        $nextFree = $context->builder->load($context->builder->structGep($src, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, 'array_diff_ukey_packed_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, 'array_diff_ukey_packed_head');
        $packedBody = BasicBlockHelper::append($context, 'array_diff_ukey_packed_body');
        $packedTest = BasicBlockHelper::append($context, 'array_diff_ukey_packed_test');
        $packedKeep = BasicBlockHelper::append($context, 'array_diff_ukey_packed_keep');
        $packedSkip = BasicBlockHelper::append($context, 'array_diff_ukey_packed_skip');
        $packedNext = BasicBlockHelper::append($context, 'array_diff_ukey_packed_next');
        $packedDone = BasicBlockHelper::append($context, 'array_diff_ukey_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $src,
            $idx
        );
        $context->builder->branchIf($isSet, $packedTest, $packedNext);

        $context->builder->positionAtEnd($packedTest);
        $keyVar = self::indexToKeyVariable($context, $idx);
        $valueVar = HashTableHelper::readIndexedToValueBox($context, $src, $idx);
        $found = self::keyInAnyOtherWithClosure(
            $context,
            $keyVar,
            $otherHts,
            $closureCall,
            $returnTypeTag
        );
        $keep = $intersect ? $found : $context->builder->not($found);
        $context->builder->branchIf($keep, $packedKeep, $packedSkip);

        $context->builder->positionAtEnd($packedKeep);
        HashTableHelper::setAtIndex($context, $dest, $idx, $valueVar);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedSkip);
        $context->builder->branch($packedNext);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, 'array_diff_ukey_str_init');
        $strHead = BasicBlockHelper::append($context, 'array_diff_ukey_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'array_diff_ukey_walk');
        $head = $context->builder->load($context->builder->structGep($src, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, 'array_diff_ukey_str_body');
        $strTest = BasicBlockHelper::append($context, 'array_diff_ukey_str_test');
        $strKeep = BasicBlockHelper::append($context, 'array_diff_ukey_str_keep');
        $strSkip = BasicBlockHelper::append($context, 'array_diff_ukey_str_skip');
        $strNext = BasicBlockHelper::append($context, 'array_diff_ukey_str_next');
        $strDone = BasicBlockHelper::append($context, 'array_diff_ukey_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $context->builder->branch($strTest);

        $context->builder->positionAtEnd($strTest);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyVar = self::stringKeyToVariable($context, $keyStr);
        $valueVar = self::valueEntryToVariable($context, $context->builder->structGep($node, $nodeMap['value']));
        $found = self::keyInAnyOtherWithClosure(
            $context,
            $keyVar,
            $otherHts,
            $closureCall,
            $returnTypeTag
        );
        $keep = $intersect ? $found : $context->builder->not($found);
        $context->builder->branchIf($keep, $strKeep, $strSkip);

        $context->builder->positionAtEnd($strKeep);
        HashTableHelper::setAtStringKey($context, $dest, $keyStr, $valueVar);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strSkip);
        $context->builder->branch($strNext);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);

        return $dest;
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function valueInAnyOtherWithClosure(
        Context $context,
        Variable $needle,
        array $otherHts,
        Call $closureCall,
        string $returnTypeTag
    ): Value {
        if ([] === $otherHts) {
            return $context->constantFromBool(false);
        }

        $i1 = $context->getTypeFromString('int1');
        $foundSlot = $context->builder->alloca($i1, 1, 'array_udiff_found');
        $context->builder->store($i1->constInt(0, false), $foundSlot);
        $foundDone = BasicBlockHelper::append($context, 'array_udiff_found_done');
        $afterScans = BasicBlockHelper::append($context, 'array_udiff_after_scans');
        $n = \count($otherHts);
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $nextBlocks[$i] = ($i + 1 < $n)
                ? BasicBlockHelper::append($context, 'array_udiff_scan_next_'.$i)
                : $afterScans;
        }

        for ($i = 0; $i < $n; ++$i) {
            if ($i > 0) {
                $context->builder->positionAtEnd($nextBlocks[$i - 1]);
            }
            self::scanHashTableValuesWithClosure(
                $context,
                $otherHts[$i],
                $needle,
                $closureCall,
                $returnTypeTag,
                $foundSlot,
                $foundDone,
                $nextBlocks[$i],
                'array_udiff_ht_'.$i
            );
        }

        $context->builder->positionAtEnd($afterScans);
        $context->builder->branch($foundDone);
        $context->builder->positionAtEnd($foundDone);

        return $context->builder->load($foundSlot);
    }

    /**
     * @param list<Value> $otherHts
     */
    private static function keyInAnyOtherWithClosure(
        Context $context,
        Variable $needleKey,
        array $otherHts,
        Call $closureCall,
        string $returnTypeTag
    ): Value {
        if ([] === $otherHts) {
            return $context->constantFromBool(false);
        }

        $i1 = $context->getTypeFromString('int1');
        $foundSlot = $context->builder->alloca($i1, 1, 'array_diff_ukey_found');
        $context->builder->store($i1->constInt(0, false), $foundSlot);
        $foundDone = BasicBlockHelper::append($context, 'array_diff_ukey_found_done');
        $afterScans = BasicBlockHelper::append($context, 'array_diff_ukey_after_scans');
        $n = \count($otherHts);
        $nextBlocks = [];
        for ($i = 0; $i < $n; ++$i) {
            $nextBlocks[$i] = ($i + 1 < $n)
                ? BasicBlockHelper::append($context, 'array_diff_ukey_scan_next_'.$i)
                : $afterScans;
        }

        for ($i = 0; $i < $n; ++$i) {
            if ($i > 0) {
                $context->builder->positionAtEnd($nextBlocks[$i - 1]);
            }
            self::scanHashTableKeysWithClosure(
                $context,
                $otherHts[$i],
                $needleKey,
                $closureCall,
                $returnTypeTag,
                $foundSlot,
                $foundDone,
                $nextBlocks[$i],
                'array_diff_ukey_ht_'.$i
            );
        }

        $context->builder->positionAtEnd($afterScans);
        $context->builder->branch($foundDone);
        $context->builder->positionAtEnd($foundDone);

        return $context->builder->load($foundSlot);
    }

    private static function scanHashTableValuesWithClosure(
        Context $context,
        Value $ht,
        Variable $needle,
        Call $closureCall,
        string $returnTypeTag,
        Value $foundSlot,
        BasicBlock $foundDone,
        BasicBlock $nextScan,
        string $tag
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');

        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, $tag.'_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, $tag.'_packed_head');
        $packedBody = BasicBlockHelper::append($context, $tag.'_packed_body');
        $packedCmp = BasicBlockHelper::append($context, $tag.'_packed_cmp');
        $packedHit = BasicBlockHelper::append($context, $tag.'_packed_hit');
        $packedNext = BasicBlockHelper::append($context, $tag.'_packed_next');
        $packedDone = BasicBlockHelper::append($context, $tag.'_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($isSet, $packedCmp, $packedNext);

        $context->builder->positionAtEnd($packedCmp);
        $other = HashTableHelper::readIndexedToValueBox($context, $ht, $idx);
        $cmpResult = $closureCall->call($context, $needle, $other);
        $cmp = self::closureCompareToI32($context, $cmpResult, $returnTypeTag);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $context->builder->branchIf($isZero, $packedHit, $packedNext);

        $context->builder->positionAtEnd($packedHit);
        $context->builder->store($i1->constInt(1, false), $foundSlot);
        $context->builder->branch($foundDone);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, $tag.'_str_init');
        $strHead = BasicBlockHelper::append($context, $tag.'_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, $tag.'_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, $tag.'_str_body');
        $strCmp = BasicBlockHelper::append($context, $tag.'_str_cmp');
        $strHit = BasicBlockHelper::append($context, $tag.'_str_hit');
        $strNext = BasicBlockHelper::append($context, $tag.'_str_next');
        $strDone = BasicBlockHelper::append($context, $tag.'_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $other = self::valueEntryToVariable($context, $context->builder->structGep($node, $nodeMap['value']));
        $cmpResult = $closureCall->call($context, $needle, $other);
        $cmp = self::closureCompareToI32($context, $cmpResult, $returnTypeTag);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $context->builder->branchIf($isZero, $strHit, $strNext);

        $context->builder->positionAtEnd($strHit);
        $context->builder->store($i1->constInt(1, false), $foundSlot);
        $context->builder->branch($foundDone);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
        $context->builder->branch($nextScan);
    }

    private static function scanHashTableKeysWithClosure(
        Context $context,
        Value $ht,
        Variable $needleKey,
        Call $closureCall,
        string $returnTypeTag,
        Value $foundSlot,
        BasicBlock $foundDone,
        BasicBlock $nextScan,
        string $tag
    ): void {
        $map = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $i1 = $context->getTypeFromString('int1');
        $i32 = $context->getTypeFromString('int32');

        $nextFree = $context->builder->load($context->builder->structGep($ht, $map['nextFreeElement']));
        $idxSlot = $context->builder->alloca($sizeT, 1, $tag.'_idx');
        $context->builder->store($zero, $idxSlot);

        $packedHead = BasicBlockHelper::append($context, $tag.'_packed_head');
        $packedBody = BasicBlockHelper::append($context, $tag.'_packed_body');
        $packedCmp = BasicBlockHelper::append($context, $tag.'_packed_cmp');
        $packedHit = BasicBlockHelper::append($context, $tag.'_packed_hit');
        $packedNext = BasicBlockHelper::append($context, $tag.'_packed_next');
        $packedDone = BasicBlockHelper::append($context, $tag.'_packed_done');
        $context->builder->branch($packedHead);

        $context->builder->positionAtEnd($packedHead);
        $idx = $context->builder->load($idxSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idx, $nextFree);
        $context->builder->branchIf($atEnd, $packedDone, $packedBody);

        $context->builder->positionAtEnd($packedBody);
        $isSet = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $idx
        );
        $context->builder->branchIf($isSet, $packedCmp, $packedNext);

        $context->builder->positionAtEnd($packedCmp);
        $otherKey = self::indexToKeyVariable($context, $idx);
        $cmpResult = $closureCall->call($context, $needleKey, $otherKey);
        $cmp = self::closureCompareToI32($context, $cmpResult, $returnTypeTag);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $context->builder->branchIf($isZero, $packedHit, $packedNext);

        $context->builder->positionAtEnd($packedHit);
        $context->builder->store($i1->constInt(1, false), $foundSlot);
        $context->builder->branch($foundDone);

        $context->builder->positionAtEnd($packedNext);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($packedHead);

        $strInit = BasicBlockHelper::append($context, $tag.'_str_init');
        $strHead = BasicBlockHelper::append($context, $tag.'_str_head');
        $context->builder->positionAtEnd($packedDone);
        $context->builder->branch($strInit);

        $context->builder->positionAtEnd($strInit);
        $walkSlot = $context->builder->alloca($nodePtrType, 1, $tag.'_walk');
        $head = $context->builder->load($context->builder->structGep($ht, $map['strKeys']));
        $context->builder->store($head, $walkSlot);
        $strBody = BasicBlockHelper::append($context, $tag.'_str_body');
        $strCmp = BasicBlockHelper::append($context, $tag.'_str_cmp');
        $strHit = BasicBlockHelper::append($context, $tag.'_str_hit');
        $strNext = BasicBlockHelper::append($context, $tag.'_str_next');
        $strDone = BasicBlockHelper::append($context, $tag.'_str_done');
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strHead);
        $node = $context->builder->load($walkSlot);
        $nodeNull = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($nodeNull, $strDone, $strBody);

        $context->builder->positionAtEnd($strBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $otherKey = self::stringKeyToVariable($context, $keyStr);
        $cmpResult = $closureCall->call($context, $needleKey, $otherKey);
        $cmp = self::closureCompareToI32($context, $cmpResult, $returnTypeTag);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
        $context->builder->branchIf($isZero, $strHit, $strNext);

        $context->builder->positionAtEnd($strHit);
        $context->builder->store($i1->constInt(1, false), $foundSlot);
        $context->builder->branch($foundDone);

        $context->builder->positionAtEnd($strNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($strHead);

        $context->builder->positionAtEnd($strDone);
        $context->builder->branch($nextScan);
    }

    private static function valueEntryToVariable(Context $context, Value $entry): Variable
    {
        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VALUE,
            JitValueBox::pointer($context, $entry)
        );
    }

    private static function stringKeyToVariable(Context $context, Value $keyStr): Variable
    {
        return new Variable($context, Variable::TYPE_STRING, Variable::KIND_VALUE, $keyStr);
    }

    private static function indexToKeyVariable(Context $context, Value $index): Variable
    {
        $i64 = $context->getTypeFromString('int64');
        $asLong = $context->builder->truncOrBitCast($index, $i64);

        return new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $asLong);
    }

    private static function closureCompareToI32(Context $context, Value $result, string $returnTypeTag): Value
    {
        $i32 = $context->getTypeFromString('int32');
        if ('int64' === $returnTypeTag) {
            return $context->builder->truncOrBitCast($result, $i32);
        }
        if ('__value__' === $returnTypeTag) {
            $longVal = $context->builder->call(
                $context->lookupFunction('__value__readLong'),
                $result
            );

            return $context->builder->truncOrBitCast($longVal, $i32);
        }
        if ('double' === $returnTypeTag) {
            $asLong = $context->builder->call(
                $context->lookupFunction('__double__toLong'),
                $result
            );

            return $context->builder->truncOrBitCast($asLong, $i32);
        }

        throw new \LogicException(
            'array_udiff() closure return type not supported for JIT: '.$returnTypeTag
        );
    }
}
