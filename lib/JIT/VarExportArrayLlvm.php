<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Builtin\StringVarExport;
use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM var_export() on {@see __hashtable__*} (#34497).
 *
 * Thin AOT {@see StringVarExport::implementThinScalarBridge} aborted non-scalars
 * (#26855). Peer of {@see SerializeArrayLlvm} / {@see JsonEncodeArrayLlvm}: walk
 * export pairs in LLVM and build Zend-shaped `array (…)` text. Nested arrays
 * recurse via {@see StringVarExport::HT_ABI} (LLVM call, not PHP IR emission).
 *
 * php-src: ext/standard/var.c — php_var_export_ex / php_array_element_export
 */
final class VarExportArrayLlvm
{
    private static int $seq = 0;

    /**
     * Emit body for `__compiler_var_export_hashtable(ht, level)` — must be called
     * only while positioned in that function's entry (after registerFunction).
     *
     * @param Value $level native int64 indent level (0 = top-level array)
     * @param bool  $compact php_object_element_export header {@code array(} + extra space (#34506)
     */
    public static function encode(Context $context, Value $ht, Value $level, bool $compact = false): Value
    {
        StringVarExport::ensureFormatHelpersForArrayLlvm($context);

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
        $tag = (string) ++self::$seq;

        $indent = self::repeatTwoSpaces($context, $level);
        $inner = self::repeatTwoSpaces(
            $context,
            $context->builder->addNoSignedWrap($level, $oneI64)
        );
        if ($compact) {
            // php_object_element_export: one extra space on property lines (#23742 / #34506).
            $inner = JitStringConcat::concat(
                $context,
                $inner,
                $context->builder->load($context->constantStringFromString(' '))
            );
        }

        $headerLit = $compact ? "array(\n" : "array (\n";
        $header = JitStringConcat::concat(
            $context,
            $indent,
            $context->builder->load($context->constantStringFromString($headerLit))
        );

        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($header, $accSlot);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 've_ht_enc_head_'.$tag);
        $body = BasicBlockHelper::append($context, 've_ht_enc_body_'.$tag);
        $done = BasicBlockHelper::append($context, 've_ht_enc_done_'.$tag);
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

        $keyStrBlock = BasicBlockHelper::append($context, 've_ht_enc_key_str_'.$tag);
        $keyLongBlock = BasicBlockHelper::append($context, 've_ht_enc_key_long_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 've_ht_enc_key_done_'.$tag);
        $context->builder->branchIf($isLongKey, $keyLongBlock, $keyStrBlock);

        $context->builder->positionAtEnd($keyStrBlock);
        $rawKey = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        $keyWireStr = StringVarExport::formatQuotedStringLlvm($context, $rawKey);
        $keyDoneStr = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyLongBlock);
        $keyLong = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $keyWireLong = VmResourceIdString::formatNativeLong($context, $keyLong);
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

        $valHtBlock = BasicBlockHelper::append($context, 've_ht_enc_val_ht_'.$tag);
        $valOtherBlock = BasicBlockHelper::append($context, 've_ht_enc_val_other_'.$tag);
        $valDone = BasicBlockHelper::append($context, 've_ht_enc_val_done_'.$tag);
        $context->builder->branchIf($isHtVal, $valHtBlock, $valOtherBlock);

        // Nested array: Zend puts a newline after "=> " then indents the nested header
        // at the same column as the key (php_array_element_export).
        $context->builder->positionAtEnd($valHtBlock);
        $nestedHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $nestedLevel = $context->builder->addNoSignedWrap($level, $oneI64);
        $nestedFormatted = $context->builder->call(
            $context->lookupFunction(StringVarExport::HT_ABI),
            $nestedHt,
            $nestedLevel
        );
        $arrowNl = $context->builder->load($context->constantStringFromString(" => \n"));
        $lineHt = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat($context, $inner, $keyWirePhi),
                    $arrowNl
                ),
                $nestedFormatted
            ),
            $context->builder->load($context->constantStringFromString(",\n"))
        );
        $valDoneHt = $context->builder->getInsertBlock();
        $context->builder->branch($valDone);

        $context->builder->positionAtEnd($valOtherBlock);
        $scalarFormatted = $context->builder->call(
            $context->lookupFunction('__compiler_var_export'),
            $valPtr
        );
        $arrowSp = $context->builder->load($context->constantStringFromString(' => '));
        $lineOther = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat($context, $inner, $keyWirePhi),
                    $arrowSp
                ),
                $scalarFormatted
            ),
            $context->builder->load($context->constantStringFromString(",\n"))
        );
        $valDoneOther = $context->builder->getInsertBlock();
        $context->builder->branch($valDone);

        $context->builder->positionAtEnd($valDone);
        $linePhi = $context->builder->phi($strPtr);
        $linePhi->addIncoming($lineHt, $valDoneHt);
        $linePhi->addIncoming($lineOther, $valDoneOther);

        $acc = $context->builder->load($accSlot);
        $context->builder->store(JitStringConcat::concat($context, $acc, $linePhi), $accSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $accFinal = $context->builder->load($accSlot);
        $close = JitStringConcat::concat(
            $context,
            $indent,
            $context->builder->load($context->constantStringFromString(')'))
        );

        return JitStringConcat::concat($context, $accFinal, $close);
    }

    /** {@see \PHPCompiler\ext\standard\VmVarExport} indent: two spaces per level. */
    private static function repeatTwoSpaces(Context $context, Value $level): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $tag = (string) ++self::$seq;
        $unit = $context->builder->load($context->constantStringFromString('  '));
        $empty = $context->builder->load($context->constantStringFromString(''));

        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($empty, $accSlot);
        $context->builder->store($i64->constInt(0, false), $idxSlot);

        $head = BasicBlockHelper::append($context, 've_sp_head_'.$tag);
        $body = BasicBlockHelper::append($context, 've_sp_body_'.$tag);
        $done = BasicBlockHelper::append($context, 've_sp_done_'.$tag);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $i, $level);
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
