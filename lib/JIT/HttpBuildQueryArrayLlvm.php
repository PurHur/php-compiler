<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM http_build_query on {@see __hashtable__*} (#33711).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\HttpBuildQueryJitHelper} SEGVs on runtime
 * HashTable receivers. Peer of {@see JsonEncodeArrayLlvm}.
 * Callers must pass a HT loaded via {@see HashTableReadLlvm::loadHashtablePointer}.
 *
 * php-src: ext/standard/http.c — php_url_encode_hash_ex / http_build_query
 */
final class HttpBuildQueryArrayLlvm
{
    private static int $seq = 0;

    public static function build(
        Context $context,
        Value $ht,
        Value $numericPrefix,
        Value $keyPrefix,
        Value $separator,
        Value $encoding
    ): Value {
        Builtin\StringHttpBuildQuery::ensureJitHelperCompiled($context);

        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $ht);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );

        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $tag = (string) ++self::$seq;

        $empty = $context->builder->load($context->constantStringFromString(''));
        $eq = $context->builder->load($context->constantStringFromString('='));
        $openBracket = $context->builder->load($context->constantStringFromString('%5B'));
        $closeBracket = $context->builder->load($context->constantStringFromString('%5D'));
        $nestedOpen = $context->builder->load($context->constantStringFromString('%5D%5B'));

        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $needSepSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($empty, $accSlot);
        $context->builder->store($zero, $idxSlot);
        $context->builder->store($i1->constInt(0, false), $needSepSlot);

        $head = BasicBlockHelper::append($context, 'hbq_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'hbq_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'hbq_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $keyBox = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valBox = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);
        $keyPtr = JitValueBox::valuePtrFromVariable($context, $keyBox);
        $valPtr = JitValueBox::valuePtrFromVariable($context, $valBox);

        $valueMap = $context->structFieldMap['__value__'];
        $keyKind = $context->builder->and(
            $context->builder->load($context->builder->structGep($keyPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $valKind = $context->builder->and(
            $context->builder->load($context->builder->structGep($valPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );

        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(\PHPCompiler\VM\Variable::TYPE_NULL & 0x7f, false)
        );
        $skipBb = BasicBlockHelper::append($context, 'hbq_skip_'.$tag);
        $useBb = BasicBlockHelper::append($context, 'hbq_use_'.$tag);
        $context->builder->branchIf($isNull, $skipBb, $useBb);

        $context->builder->positionAtEnd($skipBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($useBb);
        $isLongKey = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $keyKind,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $keyKind,
                $i8->constInt(\PHPCompiler\VM\Variable::TYPE_INTEGER & 0x7f, false)
            )
        );
        $keyStrBb = BasicBlockHelper::append($context, 'hbq_kstr_'.$tag);
        $keyLongBb = BasicBlockHelper::append($context, 'hbq_klong_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 'hbq_kdone_'.$tag);
        $context->builder->branchIf($isLongKey, $keyLongBb, $keyStrBb);

        $context->builder->positionAtEnd($keyStrBb);
        $rawKey = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        $keyStrEnd = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyLongBb);
        $keyLong = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $keyDigits = VmResourceIdString::formatNativeLong($context, $keyLong);
        $keyLongEnd = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyDone);
        $keyStr = $context->builder->phi($strPtr);
        $keyStr->addIncoming($rawKey, $keyStrEnd);
        $keyStr->addIncoming($keyDigits, $keyLongEnd);

        // php-src ext/standard/http.c — numeric_prefix vs key_prefix (VmHttpBuildQuery).
        $keyPrefixLen = $context->builder->load(
            $context->builder->structGep($keyPrefix, $context->structFieldMap['__string__']['length'])
        );
        $hasKeyPrefix = $context->builder->icmp(Builder::INT_NE, $keyPrefixLen, $i64->constInt(0, false));
        $numPrefixLen = $context->builder->load(
            $context->builder->structGep($numericPrefix, $context->structFieldMap['__string__']['length'])
        );
        $hasNumPrefix = $context->builder->icmp(Builder::INT_NE, $numPrefixLen, $i64->constInt(0, false));

        $kpModeBb = BasicBlockHelper::append($context, 'hbq_kpmode_'.$tag);
        $rootModeBb = BasicBlockHelper::append($context, 'hbq_rootmode_'.$tag);
        $ekChildDone = BasicBlockHelper::append($context, 'hbq_ekchild_'.$tag);
        $context->builder->branchIf($hasKeyPrefix, $kpModeBb, $rootModeBb);

        $context->builder->positionAtEnd($kpModeBb);
        $ekKp = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $keyPrefix, $keyStr),
            $closeBracket
        );
        $childKp = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $keyPrefix, $keyStr),
            $nestedOpen
        );
        $kpEnd = $context->builder->getInsertBlock();
        $context->builder->branch($ekChildDone);

        $context->builder->positionAtEnd($rootModeBb);
        $intRootBb = BasicBlockHelper::append($context, 'hbq_introot_'.$tag);
        $strRootBb = BasicBlockHelper::append($context, 'hbq_strroot_'.$tag);
        $context->builder->branchIf($isLongKey, $intRootBb, $strRootBb);

        $context->builder->positionAtEnd($intRootBb);
        $intPfxBb = BasicBlockHelper::append($context, 'hbq_intpfx_'.$tag);
        $intNoPfxBb = BasicBlockHelper::append($context, 'hbq_intnopfx_'.$tag);
        $intRootDone = BasicBlockHelper::append($context, 'hbq_introot_done_'.$tag);
        $context->builder->branchIf($hasNumPrefix, $intPfxBb, $intNoPfxBb);

        $context->builder->positionAtEnd($intPfxBb);
        $ekIntPfx = JitStringConcat::concat($context, $numericPrefix, $keyStr);
        $childIntPfx = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $numericPrefix, $keyStr),
            $openBracket
        );
        $intPfxEnd = $context->builder->getInsertBlock();
        $context->builder->branch($intRootDone);

        $context->builder->positionAtEnd($intNoPfxBb);
        $childIntNo = JitStringConcat::concat($context, $keyStr, $openBracket);
        $intNoPfxEnd = $context->builder->getInsertBlock();
        $context->builder->branch($intRootDone);

        $context->builder->positionAtEnd($intRootDone);
        $ekIntRoot = $context->builder->phi($strPtr);
        $ekIntRoot->addIncoming($ekIntPfx, $intPfxEnd);
        $ekIntRoot->addIncoming($keyStr, $intNoPfxEnd);
        $childIntRoot = $context->builder->phi($strPtr);
        $childIntRoot->addIncoming($childIntPfx, $intPfxEnd);
        $childIntRoot->addIncoming($childIntNo, $intNoPfxEnd);
        $intRootEnd = $context->builder->getInsertBlock();
        $context->builder->branch($ekChildDone);

        $context->builder->positionAtEnd($strRootBb);
        $ekStrRoot = $keyStr;
        $childStrRoot = JitStringConcat::concat($context, $keyStr, $openBracket);
        $strRootEnd = $context->builder->getInsertBlock();
        $context->builder->branch($ekChildDone);

        $context->builder->positionAtEnd($ekChildDone);
        $ek = $context->builder->phi($strPtr);
        $ek->addIncoming($ekKp, $kpEnd);
        $ek->addIncoming($ekIntRoot, $intRootEnd);
        $ek->addIncoming($ekStrRoot, $strRootEnd);
        $childPrefix = $context->builder->phi($strPtr);
        $childPrefix->addIncoming($childKp, $kpEnd);
        $childPrefix->addIncoming($childIntRoot, $intRootEnd);
        $childPrefix->addIncoming($childStrRoot, $strRootEnd);

        $isHt = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $valKind,
                $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $valKind,
                $i8->constInt(\PHPCompiler\VM\Variable::TYPE_ARRAY & 0x7f, false)
            )
        );
        $nestBb = BasicBlockHelper::append($context, 'hbq_nest_'.$tag);
        $scalBb = BasicBlockHelper::append($context, 'hbq_scal_'.$tag);
        $pieceDone = BasicBlockHelper::append($context, 'hbq_piece_'.$tag);
        $context->builder->branchIf($isHt, $nestBb, $scalBb);

        $context->builder->positionAtEnd($nestBb);
        $childHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $nestedStr = $context->builder->call(
            $context->lookupFunction('__compiler_http_build_query_llvm'),
            $childHt,
            $empty,
            $childPrefix,
            $separator,
            $encoding
        );
        $nestEnd = $context->builder->getInsertBlock();
        $context->builder->branch($pieceDone);

        $context->builder->positionAtEnd($scalBb);
        $isLong = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $valKind,
                $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $valKind,
                $i8->constInt(\PHPCompiler\VM\Variable::TYPE_INTEGER & 0x7f, false)
            )
        );
        $longBb = BasicBlockHelper::append($context, 'hbq_vlong_'.$tag);
        $otherBb = BasicBlockHelper::append($context, 'hbq_vother_'.$tag);
        $scalDone = BasicBlockHelper::append($context, 'hbq_scal_done_'.$tag);
        $context->builder->branchIf($isLong, $longBb, $otherBb);

        $context->builder->positionAtEnd($longBb);
        $long = $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr);
        $longStr = VmResourceIdString::formatNativeLong($context, $long);
        $longPiece = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $ek, $eq),
            $longStr
        );
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($scalDone);

        $context->builder->positionAtEnd($otherBb);
        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $boolBb = BasicBlockHelper::append($context, 'hbq_vbool_'.$tag);
        $strCheck = BasicBlockHelper::append($context, 'hbq_vstrchk_'.$tag);
        $context->builder->branchIf($isBool, $boolBb, $strCheck);

        $context->builder->positionAtEnd($boolBb);
        $boolByte = JitValueBox::readBoolByte($context, $valPtr);
        $isTrue = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $tStr = $context->builder->load($context->constantStringFromString('1'));
        $fStr = $context->builder->load($context->constantStringFromString('0'));
        $boolStr = $context->builder->select($isTrue, $tStr, $fStr);
        $boolPiece = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $ek, $eq),
            $boolStr
        );
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($scalDone);

        $context->builder->positionAtEnd($strCheck);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $strBb = BasicBlockHelper::append($context, 'hbq_vstr_'.$tag);
        $dblCheck = BasicBlockHelper::append($context, 'hbq_vdblchk_'.$tag);
        $context->builder->branchIf($isString, $strBb, $dblCheck);

        $context->builder->positionAtEnd($strBb);
        $raw = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $strPiece = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $ek, $eq),
            $raw
        );
        $strEnd = $context->builder->getInsertBlock();
        $context->builder->branch($scalDone);

        $context->builder->positionAtEnd($dblCheck);
        $isDouble = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $valKind,
                $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $valKind,
                $i8->constInt(\PHPCompiler\VM\Variable::TYPE_FLOAT & 0x7f, false)
            )
        );
        $dblBb = BasicBlockHelper::append($context, 'hbq_vdbl_'.$tag);
        $emptyBb = BasicBlockHelper::append($context, 'hbq_vempty_'.$tag);
        $context->builder->branchIf($isDouble, $dblBb, $emptyBb);

        $context->builder->positionAtEnd($dblBb);
        $dbl = $context->builder->call($context->lookupFunction('__value__readDouble'), $valPtr);
        $dblStr = Builtin\ZendDoubleStringRuntime::format($context, $dbl);
        $dblPiece = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $ek, $eq),
            $dblStr
        );
        $dblEnd = $context->builder->getInsertBlock();
        $context->builder->branch($scalDone);

        $context->builder->positionAtEnd($emptyBb);
        $emptyPiece = $empty;
        $emptyEnd = $context->builder->getInsertBlock();
        $context->builder->branch($scalDone);

        $context->builder->positionAtEnd($scalDone);
        $scalPhi = $context->builder->phi($strPtr);
        $scalPhi->addIncoming($longPiece, $longEnd);
        $scalPhi->addIncoming($boolPiece, $boolEnd);
        $scalPhi->addIncoming($strPiece, $strEnd);
        $scalPhi->addIncoming($dblPiece, $dblEnd);
        $scalPhi->addIncoming($emptyPiece, $emptyEnd);
        $scalEnd = $context->builder->getInsertBlock();
        $context->builder->branch($pieceDone);

        $context->builder->positionAtEnd($pieceDone);
        $piece = $context->builder->phi($strPtr);
        $piece->addIncoming($nestedStr, $nestEnd);
        $piece->addIncoming($scalPhi, $scalEnd);

        $pieceLen = $context->builder->load(
            $context->builder->structGep($piece, $context->structFieldMap['__string__']['length'])
        );
        $pieceEmpty = $context->builder->icmp(Builder::INT_EQ, $pieceLen, $i64->constInt(0, false));
        $addBb = BasicBlockHelper::append($context, 'hbq_add_'.$tag);
        $afterBb = BasicBlockHelper::append($context, 'hbq_after_'.$tag);
        $context->builder->branchIf($pieceEmpty, $afterBb, $addBb);

        $context->builder->positionAtEnd($addBb);
        $needSep = $context->builder->load($needSepSlot);
        $acc = $context->builder->load($accSlot);
        $withSep = $context->builder->select(
            $needSep,
            JitStringConcat::concat($context, $acc, $separator),
            $acc
        );
        $withPiece = JitStringConcat::concat($context, $withSep, $piece);
        $context->builder->store($withPiece, $accSlot);
        $context->builder->store($i1->constInt(1, false), $needSepSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($afterBb);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($accSlot);
    }
}
