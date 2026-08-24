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
 * Call-site LLVM serialize() object property bag on {@see __hashtable__*} (#34493).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\SerializeObjectNestedJitHelper::encodeObjectProps}
 * SIGABRTs on non-empty property HTs under thin AOT (same class as #34483 encodeHashtable).
 * Peer of {@see SerializeArrayLlvm}: walk export pairs in LLVM; wire is `N:{…}` (not `a:N:{…}`).
 *
 * php-src: ext/standard/var.c — php_var_serialize object branch
 */
final class SerializeObjectPropsLlvm
{
    private static int $seq = 0;

    public static function encode(Context $context, Value $ht): Value
    {
        Builtin\StringSerialize::ensureJitHelperCompiled($context);

        $flags = $context->getTypeFromString('int64')->constInt(0, false);
        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $ht);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );

        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $tag = (string) ++self::$seq;

        // Header: N:{
        $numI64 = $context->builder->zExt($num, $i64);
        $numDigits = VmResourceIdString::formatNativeLong($context, $numI64);
        $openBrace = $context->builder->load($context->constantStringFromString(':{'));
        $header = JitStringConcat::concat($context, $numDigits, $openBrace);

        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($header, $accSlot);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'ser_obj_enc_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'ser_obj_enc_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'ser_obj_enc_done_'.$tag);
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
        $isLongKey = $context->builder->icmp(
            Builder::INT_EQ,
            $keyKind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );

        $keyStrBlock = BasicBlockHelper::append($context, 'ser_obj_enc_key_str_'.$tag);
        $keyLongBlock = BasicBlockHelper::append($context, 'ser_obj_enc_key_long_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 'ser_obj_enc_key_done_'.$tag);
        $context->builder->branchIf($isLongKey, $keyLongBlock, $keyStrBlock);

        $context->builder->positionAtEnd($keyStrBlock);
        $rawKey = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        $keyWireStr = self::quoteStringWire($context, $rawKey);
        $keyDoneStr = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        // Object props are string names; long keys still emit as s:len:"digits"; (defensive).
        $context->builder->positionAtEnd($keyLongBlock);
        $keyLong = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $keyDigits = VmResourceIdString::formatNativeLong($context, $keyLong);
        $keyWireLong = self::quoteStringWire($context, $keyDigits);
        $keyDoneLong = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyDone);
        $keyWirePhi = $context->builder->phi($strPtr);
        $keyWirePhi->addIncoming($keyWireStr, $keyDoneStr);
        $keyWirePhi->addIncoming($keyWireLong, $keyDoneLong);

        $valWire = JitSerialize::encodeBoxedValue($context, $valPtr, $flags);

        $acc = $context->builder->load($accSlot);
        $withKey = JitStringConcat::concat($context, $acc, $keyWirePhi);
        $withVal = JitStringConcat::concat($context, $withKey, $valWire);
        $context->builder->store($withVal, $accSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $accFinal = $context->builder->load($accSlot);
        $close = $context->builder->load($context->constantStringFromString('}'));

        return JitStringConcat::concat($context, $accFinal, $close);
    }

    /** PHP serialize string wire `s:len:"…";` (no escape — php_var_serialize). */
    private static function quoteStringWire(Context $context, Value $rawStr): Value
    {
        $strMap = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->load($context->builder->structGep($rawStr, $strMap['length']));
        $lenI64 = $context->builder->zExt($len, $i64);
        $lenDigits = VmResourceIdString::formatNativeLong($context, $lenI64);
        $sColon = $context->builder->load($context->constantStringFromString('s:'));
        $colonQuote = $context->builder->load($context->constantStringFromString(':"'));
        $quoteSemi = $context->builder->load($context->constantStringFromString('";'));

        return JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat($context, $sColon, $lenDigits),
                    $colonQuote
                ),
                $rawStr
            ),
            $quoteSemi
        );
    }
}
