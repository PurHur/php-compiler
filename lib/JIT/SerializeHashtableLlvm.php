<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitSerialize;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM serialize() on {@see __hashtable__*} (#34483).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\SerializeNestedJitHelper} /
 * {@see \PHPCompiler\ext\standard\SerializeHashtableNestedJitHelper} SIGABRTs on
 * `$pair[1]->toInt()` (exportKeyValuePairs dim-fetch). Peer {@see JsonEncodeArrayLlvm}
 * (#26367): walk export pairs in LLVM and build the wire string here.
 *
 * php-src: ext/standard/var.c — php_var_serialize array branch
 */
final class SerializeHashtableLlvm
{
    private static int $seq = 0;

    public static function encode(Context $context, Value $ht, Value $flags): Value
    {
        // Do not ensureLinked here — called while the HT bridge body is emitted (#34483).
        Builtin\StringSerialize::ensureJitHelperCompiled($context);

        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $ht);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );

        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $tag = (string) ++self::$seq;

        $prefix = $context->builder->load($context->constantStringFromString('a:'));
        $mid = $context->builder->load($context->constantStringFromString(':{'));
        $close = $context->builder->load($context->constantStringFromString('}'));
        $countDigits = VmResourceIdString::formatBoxedNativeLong(
            $context,
            $context->builder->zExt($num, $context->getTypeFromString('int64'))
        );
        $header = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $prefix, $countDigits),
            $mid
        );

        $accSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));
        $context->builder->store($header, $accSlot);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ser_ht_enc_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ser_ht_enc_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ser_ht_enc_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $acc = $context->builder->load($accSlot);

        $pair = HashTableReadLlvm::readIndexedToValueBox($context, $pairs, $idx);
        $pairHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::valuePtrFromVariable($context, $pair)
        );
        $keyBox = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $zero);
        $valBox = HashTableReadLlvm::readIndexedToValueBox($context, $pairHt, $one);

        $keyPtr = JitValueBox::valuePtrFromVariable($context, $keyBox);
        $valPtr = JitValueBox::valuePtrFromVariable($context, $valBox);
        $keyWire = self::encodeKey($context, $keyPtr, $tag);
        $valWire = JitSerialize::encodeBoxedValue($context, $valPtr, $flags);

        $withKey = JitStringConcat::concat($context, $acc, $keyWire);
        $withVal = JitStringConcat::concat($context, $withKey, $valWire);
        $context->builder->store($withVal, $accSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $accFinal = $context->builder->load($accSlot);

        return JitStringConcat::concat($context, $accFinal, $close);
    }

    /** Int key → `i:N;`; else string key → `s:len:"…";`. */
    private static function encodeKey(Context $context, Value $keyPtr, string $tag): Value
    {
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $keyKind = $context->builder->and(
            $context->builder->load($context->builder->structGep($keyPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $isLongKey = $context->builder->icmp(
            Builder::INT_EQ,
            $keyKind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $keyStrBlock = BasicBlockHelper::append($context, 'ser_ht_key_str_'.$tag);
        $keyLongBlock = BasicBlockHelper::append($context, 'ser_ht_key_long_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 'ser_ht_key_done_'.$tag);
        $context->builder->branchIf($isLongKey, $keyLongBlock, $keyStrBlock);

        $context->builder->positionAtEnd($keyStrBlock);
        $rawKey = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        $keyStrWire = self::quoteString($context, $rawKey);
        $keyDoneStr = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyLongBlock);
        $keyLong = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $keyDigits = VmResourceIdString::formatBoxedNativeLong($context, $keyLong);
        $iPrefix = $context->builder->load($context->constantStringFromString('i:'));
        $iSuffix = $context->builder->load($context->constantStringFromString(';'));
        $keyLongWire = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $iPrefix, $keyDigits),
            $iSuffix
        );
        $keyDoneLong = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyDone);
        $phi = $context->builder->phi($context->getTypeFromString('__string__*'));
        $phi->addIncoming($keyStrWire, $keyDoneStr);
        $phi->addIncoming($keyLongWire, $keyDoneLong);

        return $phi;
    }

    /** `s:len:"…";` — length-prefixed, no escape (php_var_serialize string). */
    private static function quoteString(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($str, $map['length']));
        $lenDigits = VmResourceIdString::formatBoxedNativeLong(
            $context,
            $context->builder->zExt($len, $context->getTypeFromString('int64'))
        );
        $p1 = $context->builder->load($context->constantStringFromString('s:'));
        $p2 = $context->builder->load($context->constantStringFromString(':"'));
        $p3 = $context->builder->load($context->constantStringFromString('";'));

        return JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat($context, $p1, $lenDigits),
                    $p2
                ),
                $str
            ),
            $p3
        );
    }
}
