<?php

declare(strict_types=1);

/**
 * Session cookie name stored in module globals. LLVM entry `__phpc_session_name_apply`
 * fills a caller {@see __value__} out-slot (#1184).
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\SessionNameRejectRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class SessionName
{
    public const APPLY_GET = 0;

    public const APPLY_SET = 1;

    public const APPLY_BOXED = 2;

    public static function implement(Context $context): void
    {
        SessionStorageGlobals::ensureGlobals($context);
        SessionStorageGlobals::implementEnsureDefaults($context);

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->context->voidType();

        $sig = $context->context->functionType(
            $void,
            false,
            $i8,
            $context->getTypeFromString('__string__*'),
            $context->getTypeFromString('__value__*'),
            $context->getTypeFromString('__value__*')
        );
        $fn = $context->module->addFunction('__phpc_session_name_apply', $sig);
        $context->registerFunction('__phpc_session_name_apply', $fn);

        $strMap = $context->structFieldMap['__string__'];
        $valMap = $context->structFieldMap['__value__'];

        $entry = $fn->appendBasicBlock('sname_apply_entry');
        $bbGet = $fn->appendBasicBlock('sname_get');
        $bbAfterGet = $fn->appendBasicBlock('sname_after_get');
        $bbSet = $fn->appendBasicBlock('sname_set');
        $bbAfterSet = $fn->appendBasicBlock('sname_after_set');
        $bbBox = $fn->appendBasicBlock('sname_box_entry');
        $bbBadOpc = $fn->appendBasicBlock('sname_bad_opc');

        $context->builder->positionAtEnd($entry);
        SessionStorageGlobals::emitCallEnsureDefaults($context);
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
        $bbMaybeString = $fn->appendBasicBlock('sname_box_maybe_string');
        $bbBoxGet = $fn->appendBasicBlock('sname_box_get');
        $bbBoxSet = $fn->appendBasicBlock('sname_box_set');
        $bbBoxBadType = $fn->appendBasicBlock('sname_box_bad_type');
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
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType || null === SessionStorageGlobals::$nameLenGlobal) {
            return;
        }
        self::emitStoreDefaultName($context);
        $i8 = $context->getTypeFromString('int8');
        $context->builder->store($i8->constInt(0, false), SessionStorageGlobals::$activeGlobal);
    }

    private static function emitStoreDefaultName(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $defaultLen = $i64->constInt(\strlen(VmSession::DEFAULT_NAME), false);
        $zero = $i64->constInt(0, false);
        $context->builder->store($defaultLen, SessionStorageGlobals::$nameLenGlobal);
        $bufPtr = $context->builder->inBoundsGEP(
            SessionStorageGlobals::$nameBufGlobal,
            $i32->constInt(0, false),
            $zero
        );
        foreach (str_split(VmSession::DEFAULT_NAME) as $i => $ch) {
            $charPtr = $context->builder->inBoundsGEP($bufPtr, $i64->constInt($i, false));
            $context->builder->store($i8->constInt(\ord($ch), false), $charPtr);
        }
        $nulPtr = $context->builder->inBoundsGEP($bufPtr, $defaultLen);
        $context->builder->store($i8->constInt(0, false), $nulPtr);
    }

    private static function emitWriteCurrentAsString(Context $context, Value $outPtr): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $len = $context->builder->load(SessionStorageGlobals::$nameLenGlobal);
        $bufPtr = $context->builder->inBoundsGEP(
            SessionStorageGlobals::$nameBufGlobal,
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
        $maxLen = $i64->constInt(VmSession::MAX_NAME_LEN, false);
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Value\Function_);

        $bbCheckReject = $fn->appendBasicBlock('sname_check_reject');
        $bbReject = $fn->appendBasicBlock('sname_reject');
        $bbFail = $fn->appendBasicBlock('sname_set_fail');
        $bbCopy = $fn->appendBasicBlock('sname_copy');
        $bbClamp = $fn->appendBasicBlock('sname_clamp_len');
        $bbStore = $fn->appendBasicBlock('sname_store');
        $bbDone = $fn->appendBasicBlock('sname_set_done');

        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $i8->constInt(0, false));
        $context->builder->branchIf($isActive, $bbFail, $bbCheckReject);

        $context->builder->positionAtEnd($bbCheckReject);
        SessionNameRejectRuntime::ensureLinked($context);
        $rejected = $context->builder->call(
            SessionNameRejectRuntime::isRejectedFunction($context),
            $newStr
        );
        $isRejected = $context->castToBool($rejected);
        $context->builder->branchIf($isRejected, $bbReject, $bbCopy);

        $context->builder->positionAtEnd($bbReject);
        $warningMsg = $context->builder->call(
            SessionNameRejectRuntime::warningMessageFunction($context),
            $newStr
        );
        SessionNameRejectRuntime::emitWarningFromString($context, $warningMsg);
        self::emitWriteCurrentAsString($context, $outPtr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCopy);
        $newLen = $context->builder->load(
            $context->builder->structGep($newStr, $strMap['length'])
        );
        self::emitWriteCurrentAsString($context, $outPtr);
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $newLen, $maxLen);
        $context->builder->branchIf($tooLong, $bbClamp, $bbStore);

        $context->builder->positionAtEnd($bbClamp);
        $context->builder->branch($bbStore);

        $context->builder->positionAtEnd($bbStore);
        $storeLen = $context->builder->select($tooLong, $maxLen, $newLen);
        $context->builder->store($storeLen, SessionStorageGlobals::$nameLenGlobal);
        $newBytes = $context->builder->structGep($newStr, $strMap['value']);
        $bufPtr = $context->builder->inBoundsGEP(
            SessionStorageGlobals::$nameBufGlobal,
            $i32->constInt(0, false),
            $zero
        );
        $context->intrinsic->memcpy($bufPtr, $newBytes, $storeLen, false);
        $nulPtr = $context->builder->inBoundsGEP($bufPtr, $storeLen);
        $context->builder->store($i8->constInt(0, false), $nulPtr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbFail);
        self::emitWriteBoolFalse($context, $outPtr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
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
