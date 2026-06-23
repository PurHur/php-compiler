<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * Serialize __value__ argv into a blob for {@see PackJitHelper} (#9133).
 *
 * Thin LLVM glue; pack semantics live in {@see \PHPCompiler\ext\standard\PackEngine}.
 */
final class PackArgvSerialize
{
    private const MAX_BLOB = 65536;

    private const TAG_NULL = 0;

    private const TAG_LONG = 1;

    private const TAG_DOUBLE = 2;

    private const TAG_BOOL = 3;

    private const TAG_STRING = 4;

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_pack_argv_serialize');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('phpc_pack_argv_serialize', $probe);

            return;
        }

        self::ensureLibc($context);
        self::ensureValueReaders($context);
        self::implement($context);
    }

    private static function implement(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $valuePtr = $context->getTypeFromString('__value__*');
        $ft = $context->context->functionType($strPtr, false, $i64, $valuePtr);
        $fn = $context->module->addFunction('phpc_pack_argv_serialize', $ft);

        $entry = $fn->appendBasicBlock('pack_ser_entry');
        $empty = $fn->appendBasicBlock('pack_ser_empty');
        $init = $fn->appendBasicBlock('pack_ser_init');
        $context->builder->positionAtEnd($entry);
        $argc = $fn->getParam(0);
        $argv = $fn->getParam(1);
        $zeroI64 = $i64->constInt(0, false);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $argc, $zeroI64),
            $empty,
            $init
        );

        $i8p = $context->getTypeFromString('int8*');
        $context->builder->positionAtEnd($empty);
        $context->builder->returnValue($context->builder->call(
            $context->lookupFunction('__string__init'),
            $zeroI64,
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        ));

        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $i8 = $context->getTypeFromString('int8');
        $valueMap = $context->structFieldMap['__value__'];
        $stringMap = $context->structFieldMap['__string__'];

        $context->builder->positionAtEnd($init);
        $buf = $context->builder->pointerCast(
            $context->builder->call(
                $context->lookupFunction('malloc'),
                $sizeT->constInt(self::MAX_BLOB, false)
            ),
            $i8p
        );
        $posSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($sizeT->constInt(0, false), $posSlot);
        $context->builder->store($zeroI64, $iSlot);

        $head = $fn->appendBasicBlock('pack_ser_head');
        $body = $fn->appendBasicBlock('pack_ser_body');
        $done = $fn->appendBasicBlock('pack_ser_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $argc),
            $body,
            $done
        );

        $context->builder->positionAtEnd($body);
        $entryPtr = $context->builder->gep($argv, $i);
        $typeByte = $context->builder->load($context->builder->structGep($entryPtr, $valueMap['type']));
        self::emitSerializeValue($context, $fn, $buf, $posSlot, $entryPtr, $typeByte, $valueMap, $stringMap);
        $context->builder->store($context->builder->add($i, $i64->constInt(1, false)), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $finalLen = $context->builder->load($posSlot);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($finalLen, $i64),
            $buf
        );
        $context->builder->returnValue($result);
        $context->registerFunction('phpc_pack_argv_serialize', $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function emitSerializeValue(
        Context $context,
        LlvmFunction $fn,
        Value $buf,
        Value $posSlot,
        Value $entryPtr,
        Value $typeByte,
        array $valueMap,
        array $stringMap
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $double = $context->getTypeFromString('double');

        $checkIntBb = $fn->appendBasicBlock('pack_ser_val_check_int');
        $nullBb = $fn->appendBasicBlock('pack_ser_val_null');
        $longBb = $fn->appendBasicBlock('pack_ser_val_long');
        $checkFloatBb = $fn->appendBasicBlock('pack_ser_val_check_float');
        $doubleBb = $fn->appendBasicBlock('pack_ser_val_double');
        $checkBoolBb = $fn->appendBasicBlock('pack_ser_val_check_bool');
        $boolBb = $fn->appendBasicBlock('pack_ser_val_bool');
        $checkStringBb = $fn->appendBasicBlock('pack_ser_val_check_string');
        $stringBb = $fn->appendBasicBlock('pack_ser_val_string');
        $coerceLongBb = $fn->appendBasicBlock('pack_ser_val_coerce_long');
        $after = $fn->appendBasicBlock('pack_ser_val_after');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_NULL, false));
        $context->builder->branchIf($isNull, $nullBb, $checkIntBb);

        $context->builder->positionAtEnd($checkIntBb);
        $isInt = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_INTEGER, false));
        $context->builder->branchIf($isInt, $longBb, $checkFloatBb);

        $context->builder->positionAtEnd($checkFloatBb);
        $isFloat = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_FLOAT, false));
        $context->builder->branchIf($isFloat, $doubleBb, $checkBoolBb);

        $context->builder->positionAtEnd($checkBoolBb);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_BOOLEAN, false));
        $context->builder->branchIf($isBool, $boolBb, $checkStringBb);

        $context->builder->positionAtEnd($checkStringBb);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeByte, $i8->constInt(VmVariable::TYPE_STRING, false));
        $context->builder->branchIf($isString, $stringBb, $coerceLongBb);

        $context->builder->positionAtEnd($nullBb);
        self::writeTagByte($context, $buf, $posSlot, self::TAG_NULL);
        $context->builder->branch($after);

        $context->builder->positionAtEnd($longBb);
        $longVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entryPtr);
        self::writeTagByte($context, $buf, $posSlot, self::TAG_LONG);
        self::writeI64Le($context, $buf, $posSlot, $longVal);
        $context->builder->branch($after);

        $context->builder->positionAtEnd($doubleBb);
        $dblVal = $context->builder->call($context->lookupFunction('__value__readDouble'), $entryPtr);
        self::writeTagByte($context, $buf, $posSlot, self::TAG_DOUBLE);
        self::writeDoubleLe($context, $buf, $posSlot, $dblVal);
        $context->builder->branch($after);

        $context->builder->positionAtEnd($boolBb);
        $boolVal = $context->builder->call($context->lookupFunction('__value__readLong'), $entryPtr);
        self::writeTagByte($context, $buf, $posSlot, self::TAG_BOOL);
        self::writeI64Le($context, $buf, $posSlot, $boolVal);
        $context->builder->branch($after);

        $context->builder->positionAtEnd($stringBb);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $entryPtr);
        $strSep = $context->builder->call($context->lookupFunction('__string__separate'), $strVal);
        $slen = $context->builder->load($context->builder->structGep($strSep, $stringMap['length']));
        $sdata = $context->builder->structGep($strSep, $stringMap['value']);
        self::writeTagByte($context, $buf, $posSlot, self::TAG_STRING);
        self::writeI64Le($context, $buf, $posSlot, $slen);
        $pos = $context->builder->load($posSlot);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($context->builder->gep($buf, $pos), $voidPtr),
            $context->builder->pointerCast($sdata, $voidPtr),
            $context->builder->truncOrBitCast($slen, $sizeT)
        );
        $context->builder->store(
            $context->builder->add($pos, $context->builder->truncOrBitCast($slen, $sizeT)),
            $posSlot
        );
        $context->builder->branch($after);

        $context->builder->positionAtEnd($coerceLongBb);
        $coerced = $context->builder->call($context->lookupFunction('__value__readLong'), $entryPtr);
        self::writeTagByte($context, $buf, $posSlot, self::TAG_LONG);
        self::writeI64Le($context, $buf, $posSlot, $coerced);
        $context->builder->branch($after);

        $context->builder->positionAtEnd($after);
    }

    private static function writeTagByte(Context $context, Value $buf, Value $posSlot, int $tag): void
    {
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $pos = $context->builder->load($posSlot);
        $at = $context->builder->gep($buf, $pos);
        $context->builder->store($i8->constInt($tag, false), $at);
        $context->builder->store($context->builder->add($pos, $sizeT->constInt(1, false)), $posSlot);
    }

    private static function writeI64Le(Context $context, Value $buf, Value $posSlot, Value $val): void
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $pos = $context->builder->load($posSlot);
        $mem = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($val, $mem);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($context->builder->gep($buf, $pos), $voidPtr),
            $context->builder->pointerCast($mem, $voidPtr),
            $sizeT->constInt(8, false)
        );
        $context->builder->store($context->builder->add($pos, $sizeT->constInt(8, false)), $posSlot);
    }

    private static function writeDoubleLe(Context $context, Value $buf, Value $posSlot, Value $val): void
    {
        $double = $context->getTypeFromString('double');
        $sizeT = $context->getTypeFromString('size_t');
        $voidPtr = $context->getTypeFromString('void*');
        $pos = $context->builder->load($posSlot);
        $mem = BasicBlockHelper::entryAlloca($context, $double);
        $context->builder->store($val, $mem);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($context->builder->gep($buf, $pos), $voidPtr),
            $context->builder->pointerCast($mem, $voidPtr),
            $sizeT->constInt(8, false)
        );
        $context->builder->store($context->builder->add($pos, $sizeT->constInt(8, false)), $posSlot);
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['malloc', $voidPtr, [$sizeT]],
                ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }

    private static function ensureValueReaders(Context $context): void
    {
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');

        foreach (
            [
                ['__value__readLong', $i64, [$valuePtr]],
                ['__value__readDouble', $double, [$valuePtr]],
                ['__value__readString', $strPtr, [$valuePtr]],
                ['__string__separate', $strPtr, [$strPtr]],
                ['__string__init', $strPtr, [$i64, $context->getTypeFromString('int8*')]],
            ] as [$name, $ret, $params]
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, false, ...$params)
                );
                $context->registerFunction($name, $fn);
            }
        }
    }
}
