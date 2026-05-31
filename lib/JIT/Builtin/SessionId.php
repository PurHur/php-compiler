<?php

declare(strict_types=1);

/**
 * Session id string stored in module globals. LLVM entry `__phpc_session_id_apply`
 * fills a caller {@see __value__} out-slot (#1183).
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class SessionId
{
    public const MAX_LEN = 128;

    public const GLOBAL_BUF = '__phpc_session_id_storage';

    public const GLOBAL_LEN = '__phpc_session_id_len';

    public const APPLY_GET = 0;

    public const APPLY_SET = 1;

    public const APPLY_BOXED = 2;

    /** @var \PHPLLVM\Value|null */
    public static $bufGlobal = null;

    /** @var \PHPLLVM\Value|null */
    public static $lenGlobal = null;

    public static function implement(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->context->voidType();
        $bufType = $context->getTypeFromString('int8['.(self::MAX_LEN + 1).']');

        self::$bufGlobal = $context->module->addGlobal($bufType, self::GLOBAL_BUF);
        self::$lenGlobal = $context->module->addGlobal($i64, self::GLOBAL_LEN);
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            self::$lenGlobal->setInitializer($i64->constInt(0, false));
        }

        $sig = $context->context->functionType(
            $void,
            false,
            $i8,
            $context->getTypeFromString('__string__*'),
            $context->getTypeFromString('__value__*'),
            $context->getTypeFromString('__value__*')
        );
        $fn = $context->module->addFunction('__phpc_session_id_apply', $sig);
        $context->registerFunction('__phpc_session_id_apply', $fn);

        $strMap = $context->structFieldMap['__string__'];
        $valMap = $context->structFieldMap['__value__'];

        $entry = $fn->appendBasicBlock('sid_apply_entry');
        $bbGet = $fn->appendBasicBlock('sid_get');
        $bbAfterGet = $fn->appendBasicBlock('sid_after_get');
        $bbSet = $fn->appendBasicBlock('sid_set');
        $bbAfterSet = $fn->appendBasicBlock('sid_after_set');
        $bbBox = $fn->appendBasicBlock('sid_box_entry');
        $bbBadOpc = $fn->appendBasicBlock('sid_bad_opc');

        $context->builder->positionAtEnd($entry);
        $opc = $fn->getParam(0);
        $strArg = $fn->getParam(1);
        $boxedPtr = $fn->getParam(2);
        $outPtr = $fn->getParam(3);

        $isGet = $context->builder->icmp(Builder::INT_EQ, $opc, $i8->constInt(self::APPLY_GET, false));
        $context->builder->branchIf($isGet, $bbGet, $bbAfterGet);

        $context->builder->positionAtEnd($bbGet);
        self::emitWriteCurrentAsString($context, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbAfterGet);
        $isSet = $context->builder->icmp(Builder::INT_EQ, $opc, $i8->constInt(self::APPLY_SET, false));
        $context->builder->branchIf($isSet, $bbSet, $bbAfterSet);

        $context->builder->positionAtEnd($bbSet);
        self::emitSetFromString($context, $strArg, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbAfterSet);
        $isBox = $context->builder->icmp(Builder::INT_EQ, $opc, $i8->constInt(self::APPLY_BOXED, false));
        $context->builder->branchIf($isBox, $bbBox, $bbBadOpc);

        $context->builder->positionAtEnd($bbBox);
        $typeByte = $context->builder->load(
            $context->builder->structGep($boxedPtr, $valMap['type'])
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $bbMaybeString = $fn->appendBasicBlock('sid_box_maybe_string');
        $bbBoxGet = $fn->appendBasicBlock('sid_box_get');
        $bbBoxSet = $fn->appendBasicBlock('sid_box_set');
        $bbBoxBadType = $fn->appendBasicBlock('sid_box_bad_type');
        $context->builder->branchIf($isNull, $bbBoxGet, $bbMaybeString);

        $context->builder->positionAtEnd($bbBoxGet);
        self::emitWriteCurrentAsString($context, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbMaybeString);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $bbBoxSet, $bbBoxBadType);

        $context->builder->positionAtEnd($bbBoxSet);
        $boxedStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $boxedPtr
        );
        self::emitSetFromString($context, $boxedStr, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbBoxBadType);
        self::emitWriteBoolFalse($context, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbBadOpc);
        self::emitWriteBoolFalse($context, $outPtr);
        $context->builder->returnVoid();

        $context->builder->clearInsertionPosition();
    }

    public static function emitResetForStandaloneMain(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType || null === self::$lenGlobal) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $context->builder->store($zero, self::$lenGlobal);
        $bufPtr = $context->builder->inBoundsGEP(
            self::$bufGlobal,
            $context->getTypeFromString('int32')->constInt(0, false),
            $zero
        );
        $context->builder->store($i8->constInt(0, false), $bufPtr);
    }

    private static function emitWriteCurrentAsString(Context $context, Value $outPtr): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $len = $context->builder->load(self::$lenGlobal);
        $bufPtr = $context->builder->inBoundsGEP(
            self::$bufGlobal,
            $context->getTypeFromString('int32')->constInt(0, false),
            $zero
        );
        $bytes = $context->builder->pointerCast($bufPtr, $i8p);
        $str = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $bytes
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $outPtr,
            $str
        );
    }

    private static function emitSetFromString(Context $context, Value $newStr, Value $outPtr): void
    {
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $maxLen = $i64->constInt(self::MAX_LEN, false);

        self::emitWriteCurrentAsString($context, $outPtr);

        $newLen = $context->builder->load(
            $context->builder->structGep($newStr, $strMap['length'])
        );
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $newLen, $maxLen);
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Value\Function_);
        $bbClamp = $fn->appendBasicBlock('sid_clamp_len');
        $bbCopy = $fn->appendBasicBlock('sid_copy');
        $context->builder->branchIf($tooLong, $bbClamp, $bbCopy);

        $context->builder->positionAtEnd($bbClamp);
        $context->builder->branch($bbCopy);

        $context->builder->positionAtEnd($bbCopy);
        $storeLen = $context->builder->select($tooLong, $maxLen, $newLen);
        $context->builder->store($storeLen, self::$lenGlobal);
        $newBytes = $context->builder->structGep($newStr, $strMap['value']);
        $bufPtr = $context->builder->inBoundsGEP(
            self::$bufGlobal,
            $i32->constInt(0, false),
            $zero
        );
        $context->intrinsic->memcpy($bufPtr, $newBytes, $storeLen, false);
        $nulPtr = $context->builder->inBoundsGEP($bufPtr, $storeLen);
        $context->builder->store($i8->constInt(0, false), $nulPtr);
    }

    private static function emitWriteBoolFalse(Context $context, Value $outPtr): void
    {
        $valMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store(
            $i8->constInt(Variable::TYPE_NATIVE_BOOL, false),
            $context->builder->structGep($outPtr, $valMap['type'])
        );
        $valueField = $context->builder->structGep($outPtr, $valMap['value']);
        $firstByte = $context->builder->inBoundsGEP(
            $valueField,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $context->builder->store($i8->constInt(0, false), $firstByte);
    }
}
