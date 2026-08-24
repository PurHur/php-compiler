<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\ext\standard\JitGetObjectVars;
use PHPCompiler\ext\standard\JitStringConcat;
use PHPCompiler\JIT\Builtin\StringVarExport;
use PHPCompiler\JIT\ReflectionBuiltinHelper;
use PHPCompiler\JIT\Call\HashTableExportKeyValuePairs;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Call-site LLVM var_export() object formatting (#34506).
 *
 * Thin AOT {@see StringVarExport} aborted TYPE_OBJECT (#26855). Peer of
 * {@see VarExportArrayLlvm} / {@see SerializeObjectPropsLlvm}: format an already
 * extracted class name + property HT into Zend `(object) array(…)` /
 * `\Class::__set_state(array(…))` text.
 *
 * Props must be extracted by the caller via {@see \PHPCompiler\ext\standard\JitGetObjectVars}
 * at the var_export() call site (or nested array walk). Baking get_object_vars into
 * this shared ABI freezes an empty stdClass prop map when ensureLinked runs before
 * `(object)[…]` defineProperty (#34506). Peer: JitSerialize::encodePublicObjectProps.
 *
 * php-src: ext/standard/var.c — php_var_export_ex / php_object_element_export
 */
final class VarExportObjectLlvm
{
    private static int $seq = 0;

    /**
     * Emit body for `__compiler_var_export_object(className, ht, level)`.
     *
     * @param Value $level native int64 indent level (0 = top-level object)
     */
    public static function encode(Context $context, Value $className, Value $ht, Value $level): Value
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

        // Compact object bag: "array(\n" + 3 spaces at level 0 (php_object_element_export).
        $indent = self::repeatTwoSpaces($context, $level);
        $innerBase = self::repeatTwoSpaces(
            $context,
            $context->builder->addNoSignedWrap($level, $oneI64)
        );
        $inner = JitStringConcat::concat(
            $context,
            $innerBase,
            $context->builder->load($context->constantStringFromString(' '))
        );

        $header = $context->builder->load($context->constantStringFromString("array(\n"));

        $accSlot = BasicBlockHelper::entryAlloca($context, $strPtr);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($header, $accSlot);
        $context->builder->store($zero, $idxSlot);

        $head = BasicBlockHelper::append($context, 've_obj_enc_head_'.$tag);
        $body = BasicBlockHelper::append($context, 've_obj_enc_body_'.$tag);
        $done = BasicBlockHelper::append($context, 've_obj_enc_done_'.$tag);
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

        $keyStrBlock = BasicBlockHelper::append($context, 've_obj_enc_key_str_'.$tag);
        $keyLongBlock = BasicBlockHelper::append($context, 've_obj_enc_key_long_'.$tag);
        $keyDone = BasicBlockHelper::append($context, 've_obj_enc_key_done_'.$tag);
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
        $isObjVal = $context->builder->icmp(
            Builder::INT_EQ,
            $valKind,
            $i8->constInt(Variable::TYPE_OBJECT & 0x7f, false)
        );

        $valHtBlock = BasicBlockHelper::append($context, 've_obj_enc_val_ht_'.$tag);
        $valObjBlock = BasicBlockHelper::append($context, 've_obj_enc_val_obj_'.$tag);
        $valOtherBlock = BasicBlockHelper::append($context, 've_obj_enc_val_other_'.$tag);
        $valDone = BasicBlockHelper::append($context, 've_obj_enc_val_done_'.$tag);
        $afterHt = BasicBlockHelper::append($context, 've_obj_enc_after_ht_'.$tag);
        $arrowNl = $context->builder->load($context->constantStringFromString(" => \n"));
        $context->builder->branchIf($isHtVal, $valHtBlock, $afterHt);

        $context->builder->positionAtEnd($valHtBlock);
        $nestedHt = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            $valPtr
        );
        $nestedLevel = $context->builder->addNoSignedWrap($level, $oneI64);
        $nestedHtFormatted = $context->builder->call(
            $context->lookupFunction(StringVarExport::HT_ABI),
            $nestedHt,
            $nestedLevel
        );
        $lineHt = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    JitStringConcat::concat($context, $inner, $keyWirePhi),
                    $arrowNl
                ),
                $nestedHtFormatted
            ),
            $context->builder->load($context->constantStringFromString(",\n"))
        );
        $valDoneHt = $context->builder->getInsertBlock();
        $context->builder->branch($valDone);

        $context->builder->positionAtEnd($afterHt);
        $context->builder->branchIf($isObjVal, $valObjBlock, $valOtherBlock);

        // Nested object: extract at this IR site (HT/OBJ walk) then format.
        $context->builder->positionAtEnd($valObjBlock);
        $nestedFormatted = $context->builder->call(
            $context->lookupFunction(StringVarExport::OBJ_VALUE_ABI),
            $valPtr,
            $context->builder->addNoSignedWrap($level, $oneI64)
        );
        $lineObj = JitStringConcat::concat(
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
        $valDoneObj = $context->builder->getInsertBlock();
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
        $linePhi->addIncoming($lineObj, $valDoneObj);
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
        $bag = JitStringConcat::concat($context, $accFinal, $close);

        return self::wrapClassExport($context, $className, $bag);
    }

    /**
     * Extract class name + props from a boxed object value, then format.
     * Used for nested objects and the thin-bridge object arm (#34506).
     */
    public static function encodeFromValueBox(Context $context, Value $valuePtr, Value $level): Value
    {
        $objPtr = $context->builder->call(
            $context->lookupFunction('__value__readObject'),
            $valuePtr
        );
        $objVar = new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $objPtr);
        $className = ReflectionBuiltinHelper::getClassName($context, $objVar);
        $varsBoxed = JitGetObjectVars::invoke($context, $objVar, false);
        $ht = $context->builder->call(
            $context->lookupFunction('__value__readHashtable'),
            JitValueBox::normalizeValuePtr($context, $varsBoxed)
        );

        return $context->builder->call(
            $context->lookupFunction(StringVarExport::OBJ_ABI),
            $className,
            $ht,
            $level
        );
    }

    /** stdClass → `(object) array(…)`; else `\Name::__set_state(array(…))`. */
    private static function wrapClassExport(Context $context, Value $className, Value $bag): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $tag = (string) ++self::$seq;

        $stdName = $context->builder->load($context->constantStringFromString('stdClass'));
        $cmp = JitStringCompare::strcmp($context, $className, $stdName);
        $isStd = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $i64->constInt(0, false)
        );

        $stdBlock = BasicBlockHelper::append($context, 've_obj_wrap_std_'.$tag);
        $namedBlock = BasicBlockHelper::append($context, 've_obj_wrap_named_'.$tag);
        $done = BasicBlockHelper::append($context, 've_obj_wrap_done_'.$tag);
        $context->builder->branchIf($isStd, $stdBlock, $namedBlock);

        $context->builder->positionAtEnd($stdBlock);
        $stdOut = JitStringConcat::concat(
            $context,
            $context->builder->load($context->constantStringFromString('(object) ')),
            $bag
        );
        $stdEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($namedBlock);
        $namedOut = JitStringConcat::concat(
            $context,
            JitStringConcat::concat(
                $context,
                JitStringConcat::concat(
                    $context,
                    $context->builder->load($context->constantStringFromString('\\')),
                    $className
                ),
                $context->builder->load($context->constantStringFromString('::__set_state('))
            ),
            JitStringConcat::concat(
                $context,
                $bag,
                $context->builder->load($context->constantStringFromString(')'))
            )
        );
        $namedEnd = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($stdOut, $stdEnd);
        $phi->addIncoming($namedOut, $namedEnd);

        return $phi;
    }

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

        $head = BasicBlockHelper::append($context, 've_obj_sp_head_'.$tag);
        $body = BasicBlockHelper::append($context, 've_obj_sp_body_'.$tag);
        $done = BasicBlockHelper::append($context, 've_obj_sp_done_'.$tag);
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
