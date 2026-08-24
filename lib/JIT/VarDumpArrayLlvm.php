<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Builtin\StringVarDump;
use PHPCompiler\JIT\Builtin\ZendDoubleStringRuntime;
use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM var_dump() on {@see __hashtable__*} (#34498).
 *
 * Thin AOT {@see StringVarDump::implementThinScalarBridge} aborted non-scalars
 * (#23540). Peer of {@see PrintRArrayLlvm} / {@see VarExportArrayLlvm}: walk
 * export pairs and build Zend `array(N) {…}` text. Nested arrays recurse via
 * {@see StringVarDump::HT_ABI}.
 *
 * php-src: ext/standard/var.c — php_var_dump / php_array_element_dump
 */
final class VarDumpArrayLlvm
{
    private static int $seq = 0;

    /**
     * @param Value $level native int64 dump level (1 = top-level array, matches VmVarDump)
     */
    public static function encode(Context $context, Value $ht, Value $level): Value
    {
        StringVarDump::ensureHelpersForArrayLlvm($context);

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
        $twoI64 = $i64->constInt(2, false);
        $tag = (string) ++self::$seq;

        // Header indent: dumpNested writes spaces(level-1) before dumpArray when level>1.
        $isTop = $context->builder->icmp(
            Builder::INT_EQ,
            $level,
            $oneI64
        );
        $headerSpaces = $context->builder->select(
            $isTop,
            self::emptyString($context),
            self::repeatSpaces(
                $context,
                $context->builder->sub($level, $oneI64)
            )
        );
        $keySpaces = self::repeatSpaces(
            $context,
            $context->builder->addNoSignedWrap($level, $oneI64)
        );

        $numI64 = $context->builder->zExt($num, $i64);
        $numDigits = VmResourceIdString::formatNativeLong($context, $numI64);
        $header = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    $headerSpaces,
                    $context->builder->load($context->constantStringFromString('array('))
                ),
                $numDigits
            ),
            $context->builder->load($context->constantStringFromString(") {\n"))
        );

        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($header, $accSlot);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 'vd_ht_enc_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'vd_ht_enc_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'vd_ht_enc_done_'.$tag);
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

        $keyStrBlock = BasicBlockHelper::append($context, 'vd_ht_enc_key_str_'.$tag);
        $keyLongBlock = BasicBlockHelper::append($context, 'vd_ht_enc_key_long_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 'vd_ht_enc_key_done_'.$tag);
        $context->builder->branchIf($isLongKey, $keyLongBlock, $keyStrBlock);

        $context->builder->positionAtEnd($keyStrBlock);
        $rawKey = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        // php-src php_array_element_dump string keys: ["…"]=>
        $keyWireStr = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                $context->builder->load($context->constantStringFromString('["')),
                $rawKey
            ),
            $context->builder->load($context->constantStringFromString('"]=>'))
        );
        $keyDoneStr = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyLongBlock);
        $keyLong = $context->builder->call($context->lookupFunction('__value__readLong'), $keyPtr);
        $keyDigits = VmResourceIdString::formatNativeLong($context, $keyLong);
        $keyWireLong = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                $context->builder->load($context->constantStringFromString('[')),
                $keyDigits
            ),
            $context->builder->load($context->constantStringFromString(']=>'))
        );
        $keyDoneLong = $context->builder->getInsertBlock();
        $context->builder->branch($keyDone);

        $context->builder->positionAtEnd($keyDone);
        $keyWirePhi = $context->builder->phi($strPtr);
        $keyWirePhi->addIncoming($keyWireStr, $keyDoneStr);
        $keyWirePhi->addIncoming($keyWireLong, $keyDoneLong);

        $nestedLevel = $context->builder->addNoSignedWrap($level, $twoI64);
        $valFormatted = self::formatValueAtLevel($context, $valPtr, $nestedLevel, $tag);

        $nl = $context->builder->load($context->constantStringFromString("\n"));
        $keyLine = JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $keySpaces, $keyWirePhi),
            $nl
        );
        $line = JitStringConcat::concat($context, $keyLine, $valFormatted);

        $acc = $context->builder->load($accSlot);
        $context->builder->store(JitStringConcat::concat($context, $acc, $line), $accSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $accFinal = $context->builder->load($accSlot);
        $close = JitStringConcat::concat(
            $context,
            $headerSpaces,
            $context->builder->load($context->constantStringFromString("}\n"))
        );

        return JitStringConcat::concat($context, $accFinal, $close);
    }

    /**
     * One dumpNested($level) line group: leading spaces(level-1) when level>1, then payload.
     */
    private static function formatValueAtLevel(
        Context $context,
        Value $valPtr,
        Value $level,
        string $tag
    ): Value {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $valueMap = $context->structFieldMap['__value__'];
        $oneI64 = $i64->constInt(1, false);

        $valKind = $context->builder->and(
            $context->builder->load($context->builder->structGep($valPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $isHtVal = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );

        $htBlock = BasicBlockHelper::append($context, 'vd_val_ht_'.$tag);
        $otherBlock = BasicBlockHelper::append($context, 'vd_val_other_'.$tag);
        $done = BasicBlockHelper::append($context, 'vd_val_done_'.$tag);
        $context->builder->branchIf($isHtVal, $htBlock, $otherBlock);

        $context->builder->positionAtEnd($htBlock);
        $nestedHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $nestedFormatted = $context->builder->call(
            $context->lookupFunction(StringVarDump::HT_ABI),
            $nestedHt,
            $level
        );
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($otherBlock);
        $isTopish = $context->builder->icmp(Builder::INT_SLE, $level, $oneI64);
        $indent = $context->builder->select(
            $isTopish,
            self::emptyString($context),
            self::repeatSpaces(
                $context,
                $context->builder->sub($level, $oneI64)
            )
        );
        $payload = self::formatScalarPayload($context, $valPtr, $valKind, $tag.'_sc');
        $otherFormatted = JitStringConcat::concat($context, $indent, $payload);
        $otherEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($nestedFormatted, $htEnd);
        $phi->addIncoming($otherFormatted, $otherEnd);

        return $phi;
    }

    /** Scalar/null dump payload ending in "\\n" (no indent) — php_var_dump IS_* arms. */
    private static function formatScalarPayload(
        Context $context,
        Value $valPtr,
        Value $valKind,
        string $tag
    ): Value {
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');

        $boolBlock = BasicBlockHelper::append($context, 'vd_sc_bool_'.$tag);
        $longBlock = BasicBlockHelper::append($context, 'vd_sc_long_'.$tag);
        $doubleBlock = BasicBlockHelper::append($context, 'vd_sc_double_'.$tag);
        $nullBlock = BasicBlockHelper::append($context, 'vd_sc_null_'.$tag);
        $stringBlock = BasicBlockHelper::append($context, 'vd_sc_string_'.$tag);
        $unknownBlock = BasicBlockHelper::append($context, 'vd_sc_unknown_'.$tag);
        $done = BasicBlockHelper::append($context, 'vd_sc_done_'.$tag);

        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false)
        );
        $afterBool = BasicBlockHelper::append($context, 'vd_sc_after_bool_'.$tag);
        $context->builder->branchIf($isBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $boolByte = JitValueBox::readBoolByte($context, $valPtr);
        $isTrue = $context->builder->icmp(
            Builder::INT_NE,
            $boolByte,
            $i8->constInt(0, false)
        );
        $trueStr = $context->builder->load($context->constantStringFromString("bool(true)\n"));
        $falseStr = $context->builder->load($context->constantStringFromString("bool(false)\n"));
        $boolStr = $context->builder->select($isTrue, $trueStr, $falseStr);
        $boolEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = BasicBlockHelper::append($context, 'vd_sc_after_long_'.$tag);
        $context->builder->branchIf($isLong, $longBlock, $afterLong);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr);
        $longDigits = VmResourceIdString::formatNativeLong($context, $longVal);
        $longStr = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                $context->builder->load($context->constantStringFromString('int(')),
                $longDigits
            ),
            $context->builder->load($context->constantStringFromString(")\n"))
        );
        $longEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterLong);
        $isDouble = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_NATIVE_DOUBLE, false)
        );
        $afterDouble = BasicBlockHelper::append($context, 'vd_sc_after_double_'.$tag);
        $context->builder->branchIf($isDouble, $doubleBlock, $afterDouble);

        $context->builder->positionAtEnd($doubleBlock);
        $doubleVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $valPtr);
        $floatDigits = ZendDoubleStringRuntime::formatVarDumpH($context, $doubleVal);
        $doubleStr = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                $context->builder->load($context->constantStringFromString('float(')),
                $floatDigits
            ),
            $context->builder->load($context->constantStringFromString(")\n"))
        );
        $doubleEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterDouble);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $afterNull = BasicBlockHelper::append($context, 'vd_sc_after_null_'.$tag);
        $context->builder->branchIf($isNull, $nullBlock, $afterNull);

        $context->builder->positionAtEnd($nullBlock);
        $nullStr = $context->builder->load($context->constantStringFromString("NULL\n"));
        $nullEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterNull);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_STRING & 0x7f, false)
        );
        $context->builder->branchIf($isString, $stringBlock, $unknownBlock);

        $context->builder->positionAtEnd($stringBlock);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $lenOffset = $context->structFieldIndex($strVal, 'length');
        $strLen = $context->builder->load($context->builder->structGep($strVal, $lenOffset));
        $lenI64 = $context->builder->zExt($strLen, $context->getTypeFromString('int64'));
        $lenDigits = VmResourceIdString::formatNativeLong($context, $lenI64);
        $stringStr = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat(
                        $context,
                        $context->builder->load($context->constantStringFromString('string(')),
                        $lenDigits
                    ),
                    $context->builder->load($context->constantStringFromString(') "'))
                ),
                $strVal
            ),
            $context->builder->load($context->constantStringFromString("\"\n"))
        );
        $stringEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($unknownBlock);
        $unknownStr = $context->builder->load($context->constantStringFromString("unknown()\n"));
        $unknownEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($boolStr, $boolEnd);
        $phi->addIncoming($longStr, $longEnd);
        $phi->addIncoming($doubleStr, $doubleEnd);
        $phi->addIncoming($nullStr, $nullEnd);
        $phi->addIncoming($stringStr, $stringEnd);
        $phi->addIncoming($unknownStr, $unknownEnd);

        return $phi;
    }

    private static function emptyString(Context $context): Value
    {
        return $context->builder->load($context->constantStringFromString(''));
    }

    private static function repeatSpaces(Context $context, Value $n): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $tag = (string) ++self::$seq;
        $unit = $context->builder->load($context->constantStringFromString(' '));
        $empty = self::emptyString($context);

        // Clamp negative / huge: treat n<=0 as empty (php spaces()).
        $nonPositive = $context->builder->icmp(
            Builder::INT_SLE,
            $n,
            $i64->constInt(0, false)
        );
        $earlyDone = BasicBlockHelper::append($context, 'vd_sp_empty_'.$tag);
        $loopEntry = BasicBlockHelper::append($context, 'vd_sp_enter_'.$tag);
        $merge = BasicBlockHelper::append($context, 'vd_sp_merge_'.$tag);
        $context->builder->branchIf($nonPositive, $earlyDone, $loopEntry);

        $context->builder->positionAtEnd($earlyDone);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($loopEntry);
        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($empty, $accSlot);
        $context->builder->store($i64->constInt(0, false), $idxSlot);

        $head = BasicBlockHelper::append($context, 'vd_sp_head_'.$tag);
        $body = BasicBlockHelper::append($context, 'vd_sp_body_'.$tag);
        $done = BasicBlockHelper::append($context, 'vd_sp_done_'.$tag);
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
        $looped = $context->builder->load($accSlot);
        $loopEnd = $context->builder->getInsertBlock();
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($empty, $earlyDone);
        $phi->addIncoming($looped, $loopEnd);

        return $phi;
    }
}
