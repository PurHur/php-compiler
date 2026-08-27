<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitJsonEncode;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\ext\standard\VmJsonFlags;
use PHPCompiler\JIT\Builtin\JsonEncodeQuoteStringRuntime;
use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\JIT\Call\HashTableIsPackedList;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM json_encode on {@see __hashtable__*} (#26367).
 *
 * NestedJIT {@see \PHPCompiler\ext\standard\JsonEncodeNestedJitHelper} mis-reads
 * exportKeyValuePairs dim-fetch keys (`{"":0}`) while FE_RESET/array_keys agree with Zend.
 * Walk export pairs like {@see HashTableKeysLlvm} and build JSON in LLVM.
 *
 * php-src: ext/json/php_json.c — php_json_encode
 */
final class JsonEncodeArrayLlvm
{
    private static int $seq = 0;

    public static function encode(Context $context, Value $ht, Value $flags): Value
    {
        JsonEncodeQuoteStringRuntime::ensureLinked($context);
        Builtin\StringJsonEncode::ensureJitHelperCompiled($context);
        JsonEncodeDepthLlvm::ensureGlobals($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $tag = (string) ++self::$seq;
        $merge = BasicBlockHelper::append($context, 'json_ht_enc_merge_'.$tag);

        $enterOk = JsonEncodeDepthLlvm::tryEnter($context, $flags);
        $depthFail = BasicBlockHelper::append($context, 'json_ht_enc_depth_fail_'.$tag);
        $depthOk = BasicBlockHelper::append($context, 'json_ht_enc_depth_ok_'.$tag);
        $context->builder->branchIf($enterOk, $depthOk, $depthFail);

        $context->builder->positionAtEnd($depthFail);
        $depthFailEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($depthOk);

        $packedVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $ht
        );
        $packedNative = (new Call\HashTableIsPackedList())->call($context, $packedVar);
        // User JSON_FORCE_OBJECT=16 or internal CONTAINER_AS_OBJECT — packed lists encode as objects
        // (php-src; ArrayObject #33619 / object props #28638). Overlay must not clear user FORCE_OBJECT
        // on children (#34559); only CONTAINER_AS_OBJECT is stripped below (#34522).
        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $forceMask = $i64->constInt(
            VmJsonFlags::FORCE_OBJECT | VmJsonFlags::CONTAINER_AS_OBJECT,
            false
        );
        $forceObject = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $forceMask),
            $i64->constInt(0, false)
        );
        $packed = $context->builder->and(
            $packedNative,
            $context->builder->icmp(Builder::INT_EQ, $forceObject, $i1->constInt(0, false))
        );

        $pairs = HashTableExportKeyValuePairs::exportPairsForSlice($context, $ht);
        $num = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $pairs
        );

        $sizeT = $context->getTypeFromString('size_t');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $openAssoc = $context->builder->load($context->constantStringFromString('{'));
        $openList = $context->builder->load($context->constantStringFromString('['));
        $closeAssoc = $context->builder->load($context->constantStringFromString('}'));
        $closeList = $context->builder->load($context->constantStringFromString(']'));
        $comma = $context->builder->load($context->constantStringFromString(','));
        $colon = $context->builder->load($context->constantStringFromString(':'));
        // JSON_PRETTY_PRINT — VmJsonFormat parity; compile-time fold misses runtime OR combos (#35339).
        $isPretty = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($flags, $i64->constInt(VmJsonFlags::PRETTY_PRINT, false)),
            $i64->constInt(0, false)
        );
        $colonPretty = $context->builder->load($context->constantStringFromString(': '));
        $colon = $context->builder->select($isPretty, $colonPretty, $colon);
        $commaPretty = $context->builder->load($context->constantStringFromString(",\n    "));
        $commaSep = $context->builder->select($isPretty, $commaPretty, $comma);
        $openSuffix = $context->builder->load($context->constantStringFromString("\n    "));
        $closePrefix = $context->builder->load($context->constantStringFromString("\n"));

        $open = $context->builder->select($packed, $openList, $openAssoc);
        $close = $context->builder->select($packed, $closeList, $closeAssoc);
        $openWithSuffix = JitStringConcat::concat($context, $open, $openSuffix);
        $openStored = $context->builder->select($isPretty, $openWithSuffix, $open);

        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $context->builder->store($openStored, $accSlot);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $needCommaSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->builder->store($i1->constInt(0, false), $needCommaSlot);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'json_ht_enc_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'json_ht_enc_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'json_ht_enc_done_'.$tag);
        $childFail = BasicBlockHelper::append($context, 'json_ht_enc_child_fail_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $num);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $needComma = $context->builder->load($needCommaSlot);
        $acc = $context->builder->load($accSlot);
        $withComma = $context->builder->select(
            $needComma,
            JitStringConcat::concat($context, $acc, $commaSep),
            $acc
        );

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

        $keyStrBlock = BasicBlockHelper::append($context, 'json_ht_enc_key_str_'.$tag);
        $keyLongBlock = BasicBlockHelper::append($context, 'json_ht_enc_key_long_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 'json_ht_enc_key_done_'.$tag);
        $context->builder->branchIf($isLongKey, $keyLongBlock, $keyStrBlock);

        $context->builder->positionAtEnd($keyStrBlock);
        $rawKey = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        $quotedKey = JsonEncodeQuoteStringRuntime::quote($context, $rawKey);
        $keyDoneStr = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyLongBlock);
        $keyLong = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $keyDigits = \PHPCompiler\VM\VmResourceIdString::formatNativeLong($context, $keyLong);
        $quotedKeyLong = JsonEncodeQuoteStringRuntime::quote($context, $keyDigits);
        $keyDoneLong = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyDone);
        $quotedKeyPhi = $context->builder->phi($strPtr);
        $quotedKeyPhi->addIncoming($quotedKey, $keyDoneStr);
        $quotedKeyPhi->addIncoming($quotedKeyLong, $keyDoneLong);

        // Strip only CONTAINER_AS_OBJECT so object-prop / ArrayObject wire does not leak into
        // children (#34522). Keep caller JSON_FORCE_OBJECT for nested arrays (#34559).
        $childFlags = $context->builder->and(
            $flags,
            $i64->constInt(~VmJsonFlags::CONTAINER_AS_OBJECT, false)
        );
        $valJson = JitJsonEncode::encodeBoxedValue($context, $valPtr, $childFlags);
        $childNull = $context->builder->icmp(Builder::INT_EQ, $valJson, $nullStr);
        $childOk = BasicBlockHelper::append($context, 'json_ht_enc_child_ok_'.$tag);
        $context->builder->branchIf($childNull, $childFail, $childOk);

        $context->builder->positionAtEnd($childFail);
        JsonEncodeDepthLlvm::leave($context);
        $childFailEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($childOk);

        $withKey = $context->builder->select(
            $packed,
            $withComma,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat($context, $withComma, $quotedKeyPhi),
                $colon
            )
        );
        $withVal = JitStringConcat::concat($context, $withKey, $valJson);

        $context->builder->store($withVal, $accSlot);
        $context->builder->store($i1->constInt(1, false), $needCommaSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $accFinal = $context->builder->load($accSlot);
        $closedCompact = JitStringConcat::concat($context, $accFinal, $close);
        $closedPretty = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $accFinal, $closePrefix),
            $close
        );
        $closed = $context->builder->select($isPretty, $closedPretty, $closedCompact);
        JsonEncodeDepthLlvm::leave($context);
        $doneEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($strPtr, 'json_ht_enc_result_'.$tag);
        $phi->addIncoming($nullStr, $depthFailEnd);
        $phi->addIncoming($nullStr, $childFailEnd);
        $phi->addIncoming($closed, $doneEnd);

        return $phi;
    }
}
