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
 * Call-site / helper LLVM var_export() on {@see __hashtable__*} (#34497).
 *
 * Thin standalone AOT {@see StringVarExport} scalar bridge aborted on arrays;
 * walk export pairs in LLVM (peer {@see SerializeArrayLlvm} / {@see JsonEncodeArrayLlvm}).
 *
 * php-src: ext/standard/var.c — php_var_export_ex / php_array_element_export
 */
final class VarExportArrayLlvm
{
    public const HELPER = '__compiler_var_export_hashtable';

    private static int $seq = 0;

    public static function ensureHelper(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::HELPER);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::HELPER, $probe);

            return;
        }

        StringVarExport::ensureExportStringHelper($context);
        Builtin\StringDir::ensureLinked($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $ft = $context->context->functionType($strPtr, false, $htPtr, $i64);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::HELPER, $ft);
        $context->registerFunction(self::HELPER, $fn);

        $savedBlock = BasicBlockHelper::tryGetInsertBlock($context);
        BasicBlockHelper::scopeLoweringToFunction($context, $fn, self::HELPER, static function () use ($context, $fn): void {
            $entry = $fn->appendBasicBlock('ve_ht_entry');
            $context->builder->positionAtEnd($entry);
            $context->builder->returnValue(
                self::encodeBody($context, $fn->getParam(0), $fn->getParam(1))
            );
        });
        BasicBlockHelper::restoreInsertBlock($context, $savedBlock);
    }

    public static function encode(Context $context, Value $ht, Value $level): Value
    {
        self::ensureHelper($context);

        return $context->builder->call(
            $context->lookupFunction(self::HELPER),
            $ht,
            $level
        );
    }

    private static function encodeBody(Context $context, Value $ht, Value $level): Value
    {
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

        $header = $context->builder->load($context->constantStringFromString("array (\n"));
        $innerIndent = self::spaces($context, $context->builder->mul(
            $context->builder->add($level, $i64->constInt(1, false)),
            $i64->constInt(2, false)
        ));
        $outerIndent = self::spaces($context, $context->builder->mul(
            $level,
            $i64->constInt(2, false)
        ));

        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($header, $accSlot);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 've_ht_head_'.$tag);
        $body = BasicBlockHelper::append($context, 've_ht_body_'.$tag);
        $done = BasicBlockHelper::append($context, 've_ht_done_'.$tag);
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

        $keyStrBlock = BasicBlockHelper::append($context, 've_ht_key_str_'.$tag);
        $keyLongBlock = BasicBlockHelper::append($context, 've_ht_key_long_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 've_ht_key_done_'.$tag);
        $context->builder->branchIf($isLongKey, $keyLongBlock, $keyStrBlock);

        $context->builder->positionAtEnd($keyStrBlock);
        $rawKey = $context->builder->call($context->lookupFunction('__value__readString'), $keyPtr);
        $keyWireStr = StringVarExport::quoteExportString($context, $rawKey);
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

        $valWire = self::formatValueAtLevel($context, $valPtr, $context->builder->add($level, $i64->constInt(1, false)));

        // php-src php_array_element_export: IS_ARRAY / IS_OBJECT break after "=> " (#34497).
        $valKind = $context->builder->and(
            $context->builder->load($context->builder->structGep($valPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $valIsHt = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $arrowSame = $context->builder->load($context->constantStringFromString(' => '));
        $arrowBreak = $context->builder->load($context->constantStringFromString(" => \n"));
        $arrow = $context->builder->select($valIsHt, $arrowBreak, $arrowSame);
        $afterArrow = $context->builder->select(
            $valIsHt,
            JitStringConcat::concat($context, $innerIndent, $valWire),
            $valWire
        );
        $commaNl = $context->builder->load($context->constantStringFromString(",\n"));
        $line = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat($context, $innerIndent, $keyWirePhi),
                    $arrow
                ),
                $afterArrow
            ),
            $commaNl
        );
        $acc = $context->builder->load($accSlot);
        $context->builder->store(JitStringConcat::concat($context, $acc, $line), $accSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $accFinal = $context->builder->load($accSlot);
        $close = $context->builder->load($context->constantStringFromString(')'));

        return JitStringConcat::concat(
            $context,
            JitStringConcat::concat($context, $accFinal, $outerIndent),
            $close
        );
    }

    /** Scalar via {@see __compiler_var_export}; nested HT via {@see HELPER}. */
    private static function formatValueAtLevel(Context $context, Value $valPtr, Value $level): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $valueMap = $context->structFieldMap['__value__'];
        $tag = (string) ++self::$seq;

        $kind = $context->builder->and(
            $context->builder->load($context->builder->structGep($valPtr, $valueMap['type'])),
            $i8->constInt(0x7f, false)
        );
        $isHt = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i8->constInt(Variable::TYPE_HASHTABLE & 0x7f, false)
        );
        $htBlock = BasicBlockHelper::append($context, 've_ht_val_ht_'.$tag);
        $scalarBlock = BasicBlockHelper::append($context, 've_ht_val_scalar_'.$tag);
        $done = BasicBlockHelper::append($context, 've_ht_val_done_'.$tag);
        $context->builder->branchIf($isHt, $htBlock, $scalarBlock);

        $context->builder->positionAtEnd($htBlock);
        $nestedHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $htStr = $context->builder->call(
            $context->lookupFunction(self::HELPER),
            $nestedHt,
            $level
        );
        $htEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($scalarBlock);
        $scalarStr = $context->builder->call(
            $context->lookupFunction('__compiler_var_export'),
            $valPtr
        );
        $scalarEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($htStr, $htEnd);
        $phi->addIncoming($scalarStr, $scalarEnd);

        return $phi;
    }

    /** Pure-LLVM peer of NestedJIT-safe spaces (avoid thin {@see StringStrRepeat}). */
    private static function spaces(Context $context, Value $n): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $tag = (string) ++self::$seq;
        $unit = $context->builder->load($context->constantStringFromString(' '));
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
        $idx = $context->builder->load($idxSlot);
        $past = $context->builder->icmp(Builder::INT_SGE, $idx, $n);
        $context->builder->branchIf($past, $done, $body);

        $context->builder->positionAtEnd($body);
        $acc = $context->builder->load($accSlot);
        $context->builder->store(JitStringConcat::concat($context, $acc, $unit), $accSlot);
        $context->builder->store(
            $context->builder->add($idx, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($accSlot);
    }
}
