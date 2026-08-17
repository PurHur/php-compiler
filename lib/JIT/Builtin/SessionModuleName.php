<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Session save-handler module name in module globals. LLVM entry `__phpc_session_module_apply` (#5749).
 */
final class SessionModuleName
{
    public const APPLY_GET = 0;

    public const APPLY_SET = 1;

    public const APPLY_BOXED = 2;

    public static function implement(Context $context): void
    {
        SessionStorageGlobals::ensureGlobals($context);
        SessionStorageGlobals::implementEnsureDefaults($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);

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
        $fn = $context->module->addFunction('__phpc_session_module_apply', $sig);
        $context->registerFunction('__phpc_session_module_apply', $fn);

        $strMap = $context->structFieldMap['__string__'];
        $valMap = $context->structFieldMap['__value__'];

        $entry = $fn->appendBasicBlock('smod_apply_entry');
        $bbGet = $fn->appendBasicBlock('smod_get');
        $bbAfterGet = $fn->appendBasicBlock('smod_after_get');
        $bbSet = $fn->appendBasicBlock('smod_set');
        $bbAfterSet = $fn->appendBasicBlock('smod_after_set');
        $bbBox = $fn->appendBasicBlock('smod_box_entry');
        $bbBadOpc = $fn->appendBasicBlock('smod_bad_opc');

        $context->builder->positionAtEnd($entry);
        SessionStorageGlobals::emitCallEnsureDefaults($context);
        self::emitEnsureDefaultModule($context);
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
        $bbMaybeString = $fn->appendBasicBlock('smod_box_maybe_string');
        $bbBoxGet = $fn->appendBasicBlock('smod_box_get');
        $bbBoxSet = $fn->appendBasicBlock('smod_box_set');
        $bbBoxBadType = $fn->appendBasicBlock('smod_box_bad_type');
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
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType || null === SessionStorageGlobals::$moduleLenGlobal) {
            return;
        }
        self::emitStoreDefaultModule($context);
    }

    private static function emitEnsureDefaultModule(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);
        $bufPtr = $context->builder->inBoundsGEP(
            SessionStorageGlobals::$moduleBufGlobal,
            $i32->constInt(0, false),
            $zeroI64
        );
        $firstByte = $context->builder->load($bufPtr);
        $needsSeed = $context->builder->icmp(Builder::INT_EQ, $firstByte, $i8->constInt(0, false));
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Value\Function_);
        $bbSeed = $fn->appendBasicBlock('smod_seed_module');
        $bbAfter = $fn->appendBasicBlock('smod_after_seed_module');
        $context->builder->branchIf($needsSeed, $bbSeed, $bbAfter);
        $context->builder->positionAtEnd($bbSeed);
        self::emitStoreDefaultModule($context);
        $context->builder->branch($bbAfter);
        $context->builder->positionAtEnd($bbAfter);
    }

    private static function emitStoreDefaultModule(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $defaultLen = $i64->constInt(\strlen(VmSession::DEFAULT_MODULE), false);
        $zero = $i64->constInt(0, false);
        $context->builder->store($defaultLen, SessionStorageGlobals::$moduleLenGlobal);
        $bufPtr = $context->builder->inBoundsGEP(
            SessionStorageGlobals::$moduleBufGlobal,
            $i32->constInt(0, false),
            $zero
        );
        foreach (str_split(VmSession::DEFAULT_MODULE) as $i => $ch) {
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
        $len = $context->builder->load(SessionStorageGlobals::$moduleLenGlobal);
        $bufPtr = $context->builder->inBoundsGEP(
            SessionStorageGlobals::$moduleBufGlobal,
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
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof Value\Function_);

        $bbCheckChange = $fn->appendBasicBlock('smod_check_change');
        $bbFailChange = $fn->appendBasicBlock('smod_fail_change');
        $bbCheckUser = $fn->appendBasicBlock('smod_check_user');
        $bbUserErr = $fn->appendBasicBlock('smod_user_err');
        $bbCheckKnown = $fn->appendBasicBlock('smod_check_known');
        $bbFailKnown = $fn->appendBasicBlock('smod_fail_known');
        $bbCopy = $fn->appendBasicBlock('smod_copy');
        $bbStore = $fn->appendBasicBlock('smod_store');
        $bbDone = $fn->appendBasicBlock('smod_set_done');

        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $i8->constInt(0, false));
        $context->builder->branchIf($isActive, $bbFailChange, $bbCheckChange);

        $context->builder->positionAtEnd($bbCheckChange);
        $headersSent = $context->builder->call($context->lookupFunction('__phpc_headers_sent'));
        $headersSentNonZero = $context->builder->icmp(Builder::INT_NE, $headersSent, $i32->constInt(0, false));
        $context->builder->branchIf($headersSentNonZero, $bbFailChange, $bbCheckUser);

        $context->builder->positionAtEnd($bbCheckUser);
        $isUser = self::emitStringEqualsCi($context, $newStr, 'user');
        $context->builder->branchIf($isUser, $bbUserErr, $bbCheckKnown);

        $context->builder->positionAtEnd($bbUserErr);
        TypeErrorRaise::emitValueError(
            $context,
            'session_module_name(): Argument #1 ($module) must not be "user"'
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($bbCheckKnown);
        $isFiles = self::emitStringEqualsCi($context, $newStr, VmSession::DEFAULT_MODULE);
        $context->builder->branchIf($isFiles, $bbCopy, $bbFailKnown);

        $context->builder->positionAtEnd($bbCopy);
        self::emitWriteCurrentAsString($context, $outPtr);
        $context->builder->branch($bbStore);

        $context->builder->positionAtEnd($bbStore);
        $storeLen = $i64->constInt(\strlen(VmSession::DEFAULT_MODULE), false);
        $context->builder->store($storeLen, SessionStorageGlobals::$moduleLenGlobal);
        $bufPtr = $context->builder->inBoundsGEP(
            SessionStorageGlobals::$moduleBufGlobal,
            $i32->constInt(0, false),
            $zero
        );
        foreach (str_split(VmSession::DEFAULT_MODULE) as $i => $ch) {
            $charPtr = $context->builder->inBoundsGEP($bufPtr, $i64->constInt($i, false));
            $context->builder->store($i8->constInt(\ord($ch), false), $charPtr);
        }
        $nulPtr = $context->builder->inBoundsGEP($bufPtr, $storeLen);
        $context->builder->store($i8->constInt(0, false), $nulPtr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbFailChange);
        self::emitWriteBoolFalse($context, $outPtr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbFailKnown);
        self::emitWriteBoolFalse($context, $outPtr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    private static function emitStringEqualsCi(Context $context, Value $str, string $literal): Value
    {
        StringCaseCompare::ensureStrcasecmpLinked($context);
        $strMap = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $bytes = $context->builder->structGep($str, $strMap['value']);
        $dataPtr = $context->builder->pointerCast($bytes, $i8p);
        $litPtr = $context->builder->pointerCast($context->constantFromString($literal), $i8p);
        $cmp = $context->builder->call($context->lookupFunction(StringCaseCompare::ABI_STRCASECMP), $dataPtr, $litPtr);

        return $context->builder->icmp(Builder::INT_EQ, $cmp, $i32->constInt(0, false));
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
