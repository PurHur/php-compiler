<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Builtin\StringPrintR;
use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM print_r() on {@see __hashtable__*} (#34497).
 *
 * Thin AOT {@see StringPrintR::implementThinScalarBridge} aborted non-scalars
 * (#24266). Peer of {@see VarExportArrayLlvm}: walk export pairs and build
 * Zend `Array\n(…)` text. Nested arrays recurse via {@see StringPrintR::HT_ABI}.
 *
 * php-src: ext/standard/var.c — zend_print_zval_r / php_array_element_export(print_r)
 */
final class PrintRArrayLlvm
{
    private static int $seq = 0;

    /**
     * @param Value $level native int64 indent level (0 = top-level array)
     */
    public static function encode(Context $context, Value $ht, Value $level): Value
    {
        StringPrintR::ensureHelpersForArrayLlvm($context);

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
        $oneI64 = $i64->constInt(1, false);
        $four = $i64->constInt(4, false);
        $tag = (string) ++self::$seq;

        // openSpaces = level==0 ? "" : spaces(4*(level+1))
        // keySpaces  = spaces(4*(level==0 ? 1 : level+2))
        $isTop = $context->builder->icmp(
            Builder::INT_EQ,
            $level,
            $i64->constInt(0, false)
        );
        $openCount = $context->builder->select(
            $isTop,
            $i64->constInt(0, false),
            $context->builder->mul(
                $four,
                $context->builder->addNoSignedWrap($level, $oneI64)
            )
        );
        $keyCount = $context->builder->select(
            $isTop,
            $four,
            $context->builder->mul(
                $four,
                $context->builder->addNoSignedWrap($level, $i64->constInt(2, false))
            )
        );
        $openSpaces = self::repeatSpaces($context, $openCount);
        $keySpaces = self::repeatSpaces($context, $keyCount);

        $header = JitStringConcat::concat(
            $context,
            $context->builder->load($context->constantStringFromString("Array\n")),
            JitStringConcat::concat(
                $context,
                $openSpaces,
                $context->builder->load($context->constantStringFromString("(\n"))
            )
        );

        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($header, $accSlot);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'pr_ht_enc_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'pr_ht_enc_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'pr_ht_enc_done_'.$tag);
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

        $keyStrBlock = BasicBlockHelper::append($context, 'pr_ht_enc_key_str_'.$tag);
        $keyLongBlock = BasicBlockHelper::append($context, 'pr_ht_enc_key_long_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 'pr_ht_enc_key_done_'.$tag);
        $context->builder->branchIf($isLongKey, $keyLongBlock, $keyStrBlock);

        $context->builder->positionAtEnd($keyStrBlock);
        $rawKey = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        $keyWireStr = self::bracketKey($context, $rawKey);
        $keyDoneStr = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyLongBlock);
        $keyLong = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $keyDigits = VmResourceIdString::formatNativeLong($context, $keyLong);
        $keyWireLong = self::bracketKey($context, $keyDigits);
        $keyDoneLong = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyDone);
        $keyWirePhi = $context->builder->phi($strPtr);
        $keyWirePhi->addIncoming($keyWireStr, $keyDoneStr);
        $keyWirePhi->addIncoming($keyWireLong, $keyDoneLong);

        $valKind = $context->builder->and(
            $context->builder->load($context->builder->structGep($valPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $isHtVal = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );

        $valHtBlock = BasicBlockHelper::append($context, 'pr_ht_enc_val_ht_'.$tag);
        $valOtherBlock = BasicBlockHelper::append($context, 'pr_ht_enc_val_other_'.$tag);
        $valDone = BasicBlockHelper::append($context, 'pr_ht_enc_val_done_'.$tag);
        $context->builder->branchIf($isHtVal, $valHtBlock, $valOtherBlock);

        $context->builder->positionAtEnd($valHtBlock);
        $nestedHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $nestedLevel = $context->builder->addNoSignedWrap($level, $oneI64);
        $nestedFormatted = $context->builder->call(
            $context->lookupFunction(StringPrintR::HT_ABI),
            $nestedHt,
            $nestedLevel
        );
        $valDoneHt = $context->builder->getInsertBlock();
        $context->builder->branch($valDone);

        $context->builder->positionAtEnd($valOtherBlock);
        $scalarFormatted = $context->builder->call(
            $context->lookupFunction('__compiler_print_r'),
            $valPtr
        );
        $valDoneOther = $context->builder->getInsertBlock();
        $context->builder->branch($valDone);

        $context->builder->positionAtEnd($valDone);
        $valPhi = $context->builder->phi($strPtr);
        $valPhi->addIncoming($nestedFormatted, $valDoneHt);
        $valPhi->addIncoming($scalarFormatted, $valDoneOther);

        $arrow = $context->builder->load($context->constantStringFromString(' => '));
        $nl = $context->builder->load($context->constantStringFromString("\n"));
        $line = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat($context, $keySpaces, $keyWirePhi),
                    $arrow
                ),
                $valPhi
            ),
            $nl
        );

        $acc = $context->builder->load($accSlot);
        $context->builder->store(JitStringConcat::concat($context, $acc, $line), $accSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $accFinal = $context->builder->load($accSlot);
        $close = JitStringConcat::concat(
            $context,
            $openSpaces,
            $context->builder->load($context->constantStringFromString(")\n"))
        );

        return JitStringConcat::concat($context, $accFinal, $close);
    }

    private static function bracketKey(Context $context, Value $inner): Value
    {
        return JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                $context->builder->load($context->constantStringFromString('[')),
                $inner
            ),
            $context->builder->load($context->constantStringFromString(']'))
        );
    }

    private static function repeatSpaces(Context $context, Value $n): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $tag = (string) ++self::$seq;
        $unit = $context->builder->load($context->constantStringFromString(' '));
        $empty = $context->builder->load($context->constantStringFromString(''));

        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($empty, $accSlot);
        $context->builder->store($i64->constInt(0, false), $idxSlot);

        $head = BasicBlockHelper::append($context, 'pr_sp_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'pr_sp_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'pr_sp_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $i, $n);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $acc = $context->builder->load($accSlot);
        $context->builder->store(JitStringConcat::concat($context, $acc, $unit), $accSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($accSlot);
    }
}
