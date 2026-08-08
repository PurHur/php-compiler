<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\ext\standard\VmSapiHeaderGuard;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for http_response_code via HttpResponseJitHelper PHP (#9344, #21441).
 *
 * Nested helper compile: {@see JitVmHelperLink::ensureCompiled} (no hand-rolled NestedJit loop).
 * Replaces LLVM module globals __phpc_http_response_status*. SSOT: {@see \PHPCompiler\ext\standard\HttpResponseJitHelper}.
 * php-src: ext/standard/head.c
 */
final class HttpResponseRuntime
{
    private const HELPER_PATH = '/ext/standard/HttpResponseJitHelper.php';

    private const RESET_HELPER = 'PHPCompiler\\ext\\standard\\HttpResponseJitHelper::reset';

    private const GET_RAW_HELPER = 'PHPCompiler\\ext\\standard\\HttpResponseJitHelper::getStatusRaw';

    private const SET_RAW_HELPER = 'PHPCompiler\\ext\\standard\\HttpResponseJitHelper::setStatusRaw';

    private const SET_VALIDATED_HELPER = 'PHPCompiler\\ext\\standard\\HttpResponseJitHelper::setStatusValidated';

    private const APPLY_GET_HELPER = 'PHPCompiler\\ext\\standard\\HttpResponseJitHelper::applyGetSentinel';

    private const APPLY_SET_HELPER = 'PHPCompiler\\ext\\standard\\HttpResponseJitHelper::applySetLong';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::RESET_HELPER,
        self::GET_RAW_HELPER,
        self::SET_RAW_HELPER,
        self::SET_VALIDATED_HELPER,
        self::APPLY_GET_HELPER,
        self::APPLY_SET_HELPER,
    ];

    private static int $emitSerial = 0;

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_http_response_code_apply');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureJitHelperCompiled($context);
        self::implementStatusBridges($context);
        self::implementApplyBridge($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    public static function emitResetForStandaloneMain(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        // Type::initialize already linked bridges; re-entering implement() during C main
        // wrapper emit clears the insert block and parentless-ifies session/progress IR (#11206).
        $context->builder->call($context->lookupFunction('__phpc_http_response_status_reset'));
    }

    public static function emitStandaloneStatusLine(Context $context, Value $code64): void
    {
        self::ensureLinked($context);
        // HttpResponseJitHelper::setStatusValidated already gates 100–599.
        // Do not emit a mid-function BB diamond here: after `(string)$arr[$k]` the
        // diamond leaves AOT heap-corrupt on the next edge (#23427 SessionsWeb
        // header(..., true, 303) flash redirect).
        $context->builder->call(
            $context->lookupFunction('__phpc_http_response_status_set_validated'),
            $context->builder->trunc($code64, $context->getTypeFromString('int32'))
        );
    }

    public static function loadStatusRaw(Context $context): Value
    {
        self::ensureLinked($context);

        return $context->builder->call($context->lookupFunction('__phpc_http_response_status_raw'));
    }

    public static function storeStatusRaw(Context $context, Value $statusI32): void
    {
        self::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__phpc_http_response_status_store_raw'),
            $statusI32
        );
    }

    private static function implementStatusBridges(Context $context): void
    {
        self::implementI32VoidBridge($context, '__phpc_http_response_status_reset', self::RESET_HELPER);
        self::implementI32Bridge($context, '__phpc_http_response_status_raw', self::GET_RAW_HELPER);
        self::implementVoidI32Bridge($context, '__phpc_http_response_status_store_raw', self::SET_RAW_HELPER);
        self::implementVoidI32Bridge($context, '__phpc_http_response_status_set_validated', self::SET_VALIDATED_HELPER);
    }

    private static function implementApplyBridge(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_http_response_code_apply');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__phpc_http_response_code_apply', $probe);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->context->voidType();
        $sig = $context->context->functionType(
            $void,
            false,
            $i8,
            $i64,
            $context->getTypeFromString('__value__*'),
            $context->getTypeFromString('__value__*')
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__phpc_http_response_code_apply', $sig);
        $context->registerFunction('__phpc_http_response_code_apply', $fn);

        $entry = $fn->appendBasicBlock('hr_apply_entry');
        $bbGet = $fn->appendBasicBlock('hr_get');
        $bbAfterGet = $fn->appendBasicBlock('hr_after_get');
        $bbSetL = $fn->appendBasicBlock('hr_set_long');
        $bbAfterSetLProbe = $fn->appendBasicBlock('hr_after_setl_probe');
        $bbBox = $fn->appendBasicBlock('hr_box_entry');
        $bbBadOpc = $fn->appendBasicBlock('hr_bad_opc');

        $context->builder->positionAtEnd($entry);
        $opc = $fn->getParam(0);
        $intval = $fn->getParam(1);
        $boxedPtr = $fn->getParam(2);
        $outPtr = $fn->getParam(3);

        $isGet = $context->builder->icmp(Builder::INT_EQ, $opc, $i8->constInt(HttpResponseCode::APPLY_GET, false));
        $context->builder->branchIf($isGet, $bbGet, $bbAfterGet);

        $context->builder->positionAtEnd($bbGet);
        self::emitWriteFromGetSentinel($context, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbAfterGet);
        $isSetLong = $context->builder->icmp(Builder::INT_EQ, $opc, $i8->constInt(HttpResponseCode::APPLY_SET_LONG, false));
        $context->builder->branchIf($isSetLong, $bbSetL, $bbAfterSetLProbe);

        $context->builder->positionAtEnd($bbSetL);
        self::emitSetLongGuarded($context, $intval, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbAfterSetLProbe);
        $isBox = $context->builder->icmp(Builder::INT_EQ, $opc, $i8->constInt(HttpResponseCode::APPLY_BOXED, false));
        $context->builder->branchIf($isBox, $bbBox, $bbBadOpc);

        $context->builder->positionAtEnd($bbBox);
        $valMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($boxedPtr, $valMap['type'])
        );
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NULL, false)
        );
        $bbMaybeEnum = $fn->appendBasicBlock('hr_box_maybe_enum');
        $bbBoxGet = $fn->appendBasicBlock('hr_box_get');
        $bbBoxSet = $fn->appendBasicBlock('hr_box_set');
        $bbBoxEnum = $fn->appendBasicBlock('hr_box_enum');
        $bbBoxBadType = $fn->appendBasicBlock('hr_box_bad_type');
        // Soft-null DEP+coerce on 8.4 — compile-time null emits DEP in JitHttpResponseCode (#21480).
        $context->builder->branchIf($isNull, $bbBoxGet, $bbMaybeEnum);

        $context->builder->positionAtEnd($bbBoxGet);
        self::emitWriteFromGetSentinel($context, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbMaybeEnum);
        $isEnumCase = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_ENUM_CASE, false)
        );
        $bbMaybeLong = $fn->appendBasicBlock('hr_box_maybe_long');
        $context->builder->branchIf($isEnumCase, $bbBoxEnum, $bbMaybeLong);

        $context->builder->positionAtEnd($bbBoxEnum);
        self::emitSetFromResponseCodeEnumCase($context, $boxedPtr, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbMaybeLong);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(Variable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isLong, $bbBoxSet, $bbBoxBadType);

        $context->builder->positionAtEnd($bbBoxSet);
        $boxedLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        self::emitSetLongGuarded($context, $boxedLong, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbBoxBadType);
        self::emitWriteBool($context, $outPtr, false);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbBadOpc);
        self::emitWriteBool($context, $outPtr, false);
        $context->builder->returnVoid();
    }

    private static function emitWriteFromGetSentinel(Context $context, Value $outPtr): void
    {
        $i64 = $context->getTypeFromString('int64');
        $sentinel = JitNestedHelperCoerce::extractLongFromHelperResult(
            $context,
            JitNestedHelperCoerce::callHelper(
                $context,
                self::helperFunction($context, self::APPLY_GET_HELPER),
                []
            ),
            $i64
        );
        self::emitWriteFromGetSentinelValue($context, $sentinel, $outPtr);
    }

    private static function emitWriteFromGetSentinelValue(Context $context, Value $sentinel, Value $outPtr): void
    {
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof LlvmFunction);
        ++self::$emitSerial;
        $sid = (string) self::$emitSerial;

        $sUnset = $fn->appendBasicBlock('hr_get_unset_'.$sid);
        $sSet = $fn->appendBasicBlock('hr_get_set_'.$sid);
        $sDone = $fn->appendBasicBlock('hr_get_done_'.$sid);

        $isUnset = $context->builder->icmp(Builder::INT_EQ, $sentinel, $i64->constInt(-1, true));
        $context->builder->branchIf($isUnset, $sUnset, $sSet);

        $context->builder->positionAtEnd($sUnset);
        self::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sSet);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $sentinel
        );
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sDone);
    }

    private static function emitWriteFromSetSentinel(Context $context, Value $sentinel, Value $outPtr): void
    {
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof LlvmFunction);
        ++self::$emitSerial;
        $sid = (string) self::$emitSerial;

        $sInvalid = $fn->appendBasicBlock('hr_set_inv_'.$sid);
        $sFirst = $fn->appendBasicBlock('hr_set_first_'.$sid);
        $sPrev = $fn->appendBasicBlock('hr_set_prev_'.$sid);
        $sDone = $fn->appendBasicBlock('hr_set_done_'.$sid);

        $isInvalid = $context->builder->icmp(Builder::INT_EQ, $sentinel, $i64->constInt(-1, true));
        $context->builder->branchIf($isInvalid, $sInvalid, $sFirst);

        $context->builder->positionAtEnd($sInvalid);
        self::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sFirst);
        $isFirst = $context->builder->icmp(Builder::INT_EQ, $sentinel, $i64->constInt(-2, true));
        $trueBlock = $fn->appendBasicBlock('hr_set_true_'.$sid);
        $context->builder->branchIf($isFirst, $trueBlock, $sPrev);

        $context->builder->positionAtEnd($trueBlock);
        self::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sPrev);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $sentinel
        );
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sDone);
    }

    private static function emitSetFromResponseCodeEnumCase(
        Context $context,
        Value $boxedPtr,
        Value $outPtr
    ): void {
        $responseCodeId = $context->type->object->responseCodeEnumClassId();
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof LlvmFunction);
        if (null === $responseCodeId) {
            self::emitWriteBool($context, $outPtr, false);

            return;
        }
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null === $enumMap || !isset($enumMap['class_id'])) {
            self::emitWriteBool($context, $outPtr, false);

            return;
        }
        $classId = $context->builder->load(
            $context->builder->structGep($boxedPtr, $enumMap['class_id'])
        );
        $i32 = $context->getTypeFromString('int32');
        $isResponseCode = $context->builder->icmp(
            Builder::INT_EQ,
            $classId,
            $i32->constInt($responseCodeId, false)
        );
        $okBlock = $fn->appendBasicBlock('hr_box_rc_ok');
        $badBlock = $fn->appendBasicBlock('hr_box_rc_bad');
        $context->builder->branchIf($isResponseCode, $okBlock, $badBlock);
        $context->builder->positionAtEnd($badBlock);
        self::emitWriteBool($context, $outPtr, false);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($okBlock);
        $boxedLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        self::emitSetLongGuarded($context, $boxedLong, $outPtr);
    }

    /**
     * php-src head.c — refuse SET when headers already sent; Warning + false (#28929).
     *
     * Reads {@see ObStorageLlvm} / ObOutput `__phpc_sapi_output_started` directly so a thin
     * `__phpc_headers_sent` stub (always 0) cannot mask body output (#20932).
     */
    private static function emitSetLongGuarded(Context $context, Value $codeI64, Value $outPtr): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof LlvmFunction);
        ++self::$emitSerial;
        $sid = (string) self::$emitSerial;

        $bbSent = $fn->appendBasicBlock('hr_set_hdr_sent_'.$sid);
        $bbOk = $fn->appendBasicBlock('hr_set_hdr_ok_'.$sid);
        $bbDone = $fn->appendBasicBlock('hr_set_hdr_done_'.$sid);

        $sent = self::emitLoadHeadersSentFlag($context);
        $isSent = $context->builder->icmp(Builder::INT_NE, $sent, $i32->constInt(0, false));
        $context->builder->branchIf($isSent, $bbSent, $bbOk);

        $context->builder->positionAtEnd($bbSent);
        self::emitCannotSetResponseCodeWarning($context);
        self::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbOk);
        self::emitWriteFromSetSentinel(
            $context,
            JitNestedHelperCoerce::extractLongFromHelperResult(
                $context,
                JitNestedHelperCoerce::callHelper(
                    $context,
                    self::helperFunction($context, self::APPLY_SET_HELPER),
                    [$codeI64]
                ),
                $i64
            ),
            $outPtr
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
    }

    /** @return Value i32 — nonzero when SAPI body output has started */
    private static function emitLoadHeadersSentFlag(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        // Type::initialize links HttpResponse before ObOutput; create the global here so the
        // load is not constant-folded to 0 and DCE'd (#28929).
        $sapi = $context->module->getNamedGlobal('__phpc_sapi_output_started');
        if (null === $sapi) {
            $sapi = $context->module->addGlobal($i32, '__phpc_sapi_output_started');
            $sapi->setInitializer($i32->constInt(0, false));
        }

        return $context->builder->load(
            $context->builder->pointerCast($sapi, $i32->pointerType(0))
        );
    }

    private static function emitCannotSetResponseCodeWarning(Context $context): void
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        StringTriggerError::ensureLinked($context);
        BasicBlockHelper::restoreInsertBlock($context, $savedInsert);

        $message = VmSapiHeaderGuard::CANNOT_SET_RESPONSE_CODE;
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $msgPtr = $context->builder->pointerCast($context->constantFromString($message), $i8p);
        $msgLen = $sizeT->constInt(\strlen($message), false);
        $emptyFile = $context->builder->pointerCast($context->constantFromString(''), $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $msgLen,
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $emptyFile,
            $i32->constInt(0, false)
        );
    }

    private static function emitWriteBool(Context $context, Value $outPtr, bool $value): void
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
        $context->builder->store($i8->constInt($value ? 1 : 0, false), $firstByte);
    }

    private static function implementI32Bridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($i32, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('hr_status_bridge_entry');
        $context->builder->positionAtEnd($entry);
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            []
        );
        $context->builder->returnValue(
            JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i32)
        );
        $context->registerFunction($abiName, $fn);
    }

    private static function implementI32VoidBridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $voidTy = $context->context->voidType();
        $ft = $context->context->functionType($voidTy, false);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('hr_status_bridge_entry');
        $context->builder->positionAtEnd($entry);
        JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            []
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function implementVoidI32Bridge(Context $context, string $abiName, string $helperLogical): void
    {
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $voidTy = $context->context->voidType();
        $i32 = $context->getTypeFromString('int32');
        $ft = $context->context->functionType($voidTy, false, $i32);
        $fn = null !== $probe ? $probe : $context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('hr_status_bridge_entry');
        $context->builder->positionAtEnd($entry);
        JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, $helperLogical),
            [JitNestedHelperCoerce::scalarToI64(
                $context,
                $fn->getParam(0),
                $i32
            )]
        );
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#21441');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#21441'
        );
    }
}
