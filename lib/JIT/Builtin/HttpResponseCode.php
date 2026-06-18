<?php

declare(strict_types=1);

/**
 * HTTP response status stored in a module i32 global. The LLVM entry
 * `__phpc_http_response_code_apply` fills a caller {@see __value__} out-slot
 * so the JIT caller needs no extra CFG (#280).
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;

final class HttpResponseCode
{
    private static int $setEmitSerial = 0;

    public const GLOBAL_NAME = '__phpc_http_response_status';

    /** Set when http_response_code($code) stores a valid status (emit Status: 200 on flush). */
    public const GLOBAL_EXPLICIT_NAME = '__phpc_http_response_status_explicit';

    public const APPLY_GET = 0;

    public const APPLY_SET_LONG = 1;

    public const APPLY_BOXED = 2;

    /** @var \PHPLLVM\Value|null */
    public static $global = null;

    /** @var \PHPLLVM\Value|null */
    public static $explicitGlobal = null;

    public static function implement(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->context->voidType();

        $probe = $context->module->getNamedGlobal(self::GLOBAL_NAME);
        if (null === $probe) {
            $probe = $context->module->addGlobal($i32, self::GLOBAL_NAME);
            $probe->setInitializer($i32->constInt(0, false));
        }
        self::$global = $probe;

        $explicitProbe = $context->module->getNamedGlobal(self::GLOBAL_EXPLICIT_NAME);
        if (null === $explicitProbe) {
            $explicitProbe = $context->module->addGlobal($i32, self::GLOBAL_EXPLICIT_NAME);
            $explicitProbe->setInitializer($i32->constInt(0, false));
        }
        self::$explicitGlobal = $explicitProbe;

        $sig = $context->context->functionType(
            $void,
            false,
            $i8,
            $i64,
            $context->getTypeFromString('__value__*'),
            $context->getTypeFromString('__value__*')
        );
        $fn = $context->module->getNamedFunction('__phpc_http_response_code_apply');
        if (null === $fn) {
            $fn = $context->module->addFunction('__phpc_http_response_code_apply', $sig);
        }
        $context->registerFunction('__phpc_http_response_code_apply', $fn);

        $valMap = $context->structFieldMap['__value__'];

        if ($fn->countBasicBlocks() > 0) {
            $context->builder->clearInsertionPosition();

            return;
        }

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

        $isGet = $context->builder->icmp(Builder::INT_EQ, $opc, $i8->constInt(self::APPLY_GET, false));
        $context->builder->branchIf($isGet, $bbGet, $bbAfterGet);

        $context->builder->positionAtEnd($bbGet);
        self::emitWriteCurrentAsLong($context, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbAfterGet);
        $isSetLong = $context->builder->icmp(Builder::INT_EQ, $opc, $i8->constInt(self::APPLY_SET_LONG, false));
        $context->builder->branchIf($isSetLong, $bbSetL, $bbAfterSetLProbe);

        $context->builder->positionAtEnd($bbSetL);
        self::emitSetFromCode64($context, $intval, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbAfterSetLProbe);
        $isBox = $context->builder->icmp(Builder::INT_EQ, $opc, $i8->constInt(self::APPLY_BOXED, false));
        $context->builder->branchIf($isBox, $bbBox, $bbBadOpc);

        /** ---- BOXED (?int): null → GET, long → SET, else abort ---- */
        $context->builder->positionAtEnd($bbBox);
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
        $context->builder->branchIf($isNull, $bbBoxGet, $bbMaybeEnum);

        $context->builder->positionAtEnd($bbBoxGet);
        self::emitWriteCurrentAsLong($context, $outPtr);
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
        self::emitSetFromCode64($context, $boxedLong, $outPtr);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($bbBoxBadType);
        self::emitWriteBoolFalse($context, $outPtr);
        $context->builder->returnVoid();

        /** ---- Unknown opcode ---- */
        $context->builder->positionAtEnd($bbBadOpc);
        self::emitWriteBoolFalse($context, $outPtr);
        $context->builder->returnVoid();

        $context->builder->clearInsertionPosition();
    }

    public static function emitResetForStandaloneMain(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType || null === self::$global) {
            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $context->builder->store($i32->constInt(0, false), self::$global);
        if (null !== self::$explicitGlobal) {
            $context->builder->store($i32->constInt(0, false), self::$explicitGlobal);
        }
    }

    private static function emitWriteCurrentAsLong(Context $context, \PHPLLVM\Value $outPtr): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        ++self::$setEmitSerial;
        $sid = (string) self::$setEmitSerial;

        $sUnset = $fn->appendBasicBlock('hr_get_unset_'.$sid);
        $sSet = $fn->appendBasicBlock('hr_get_set_'.$sid);
        $sDone = $fn->appendBasicBlock('hr_get_done_'.$sid);

        $cur = $context->builder->load(self::$global);
        $isUnset = $context->builder->icmp(Builder::INT_EQ, $cur, $i32->constInt(0, false));
        $context->builder->branchIf($isUnset, $sUnset, $sSet);

        $context->builder->positionAtEnd($sUnset);
        self::emitWriteBoolFalse($context, $outPtr);
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sSet);
        $cur64 = $context->builder->zExt($cur, $i64);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $cur64
        );
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sDone);
    }

    /**
     * Set the response status global and emit a CGI {@code Status:} line (AOT/JIT standalone).
     */
    public static function emitStandaloneStatusLine(Context $context, \PHPLLVM\Value $code64): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        ++self::$setEmitSerial;
        $sid = (string) self::$setEmitSerial;

        $sInvalid = $fn->appendBasicBlock('hr_hdr_inv_'.$sid);
        $sValid = $fn->appendBasicBlock('hr_hdr_ok_'.$sid);
        $sDone = $fn->appendBasicBlock('hr_hdr_done_'.$sid);

        $tooLow = $context->builder->icmp(Builder::INT_SLT, $code64, $i64->constInt(100, false));
        $tooHigh = $context->builder->icmp(Builder::INT_SGT, $code64, $i64->constInt(599, false));
        $bad = $context->builder->or($tooLow, $tooHigh);
        $context->builder->branchIf($bad, $sInvalid, $sValid);

        $context->builder->positionAtEnd($sInvalid);
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sValid);
        $code32 = $context->builder->trunc($code64, $i32);
        $context->builder->store($code32, self::$global);
        if (null !== self::$explicitGlobal) {
            $context->builder->store($i32->constInt(1, false), self::$explicitGlobal);
        }

        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sDone);
    }

    private static function emitSetFromCode64(Context $context, \PHPLLVM\Value $code64, \PHPLLVM\Value $outPtr): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        ++self::$setEmitSerial;
        $sid = (string) self::$setEmitSerial;

        $sInvalid = $fn->appendBasicBlock('hr_inv_'.$sid);
        $sValid = $fn->appendBasicBlock('hr_ok_'.$sid);
        $sMerge = $fn->appendBasicBlock('hr_md_'.$sid);

        $tooLow = $context->builder->icmp(Builder::INT_SLT, $code64, $i64->constInt(100, false));
        $tooHigh = $context->builder->icmp(Builder::INT_SGT, $code64, $i64->constInt(599, false));
        $bad = $context->builder->or($tooLow, $tooHigh);
        $context->builder->branchIf($bad, $sInvalid, $sValid);

        $context->builder->positionAtEnd($sInvalid);
        self::emitWriteBoolFalse($context, $outPtr);
        $context->builder->branch($sMerge);

        $context->builder->positionAtEnd($sValid);
        // php-src head.c: response_code 0 is falsy — getter only (#9306).
        $isZero = $context->builder->icmp(Builder::INT_EQ, $code64, $i64->constInt(0, false));
        $bbZeroGet = $fn->appendBasicBlock('hr_zero_get_'.$sid);
        $bbSetStore = $fn->appendBasicBlock('hr_set_store_'.$sid);
        $context->builder->branchIf($isZero, $bbZeroGet, $bbSetStore);

        $context->builder->positionAtEnd($bbZeroGet);
        self::emitWriteCurrentAsLong($context, $outPtr);
        $context->builder->branch($sMerge);

        $context->builder->positionAtEnd($bbSetStore);
        $code32 = $context->builder->trunc($code64, $i32);
        $prev = $context->builder->load(self::$global);
        $context->builder->store($code32, self::$global);
        if (null !== self::$explicitGlobal) {
            $context->builder->store($i32->constInt(1, false), self::$explicitGlobal);
        }

        $wasUnset = $context->builder->icmp(Builder::INT_EQ, $prev, $i32->constInt(0, false));
        $bbFirst = $fn->appendBasicBlock('hr_first_'.$sid);
        $bbLater = $fn->appendBasicBlock('hr_later_'.$sid);
        $context->builder->branchIf($wasUnset, $bbFirst, $bbLater);

        $context->builder->positionAtEnd($bbFirst);
        self::emitWriteBoolTrue($context, $outPtr);
        $context->builder->branch($sMerge);

        $context->builder->positionAtEnd($bbLater);
        $prev64 = $context->builder->zExt($prev, $i64);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $outPtr,
            $prev64
        );
        $context->builder->branch($sMerge);

        $context->builder->positionAtEnd($sMerge);
    }

    private static function emitSetFromResponseCodeEnumCase(
        Context $context,
        \PHPLLVM\Value $boxedPtr,
        \PHPLLVM\Value $outPtr
    ): void {
        $responseCodeId = $context->type->object->responseCodeEnumClassId();
        $fn = $context->builder->getInsertBlock()->getParent();
        assert($fn instanceof \PHPLLVM\Value\Function_);
        if (null === $responseCodeId) {
            self::emitWriteBoolFalse($context, $outPtr);

            return;
        }
        $enumMap = $context->structFieldMap['__enum_case__'] ?? null;
        if (null === $enumMap || !isset($enumMap['class_id'])) {
            self::emitWriteBoolFalse($context, $outPtr);

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
        self::emitWriteBoolFalse($context, $outPtr);
        $context->builder->returnVoid();
        $context->builder->positionAtEnd($okBlock);
        $boxedLong = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $boxedPtr
        );
        self::emitSetFromCode64($context, $boxedLong, $outPtr);
    }

    /** Match {@see JIT\JitValueBox::writeBool(..., false)} for outPtr. */
    private static function emitWriteBoolFalse(Context $context, \PHPLLVM\Value $outPtr): void
    {
        self::emitWriteBool($context, $outPtr, false);
    }

    private static function emitWriteBoolTrue(Context $context, \PHPLLVM\Value $outPtr): void
    {
        self::emitWriteBool($context, $outPtr, true);
    }

    private static function emitWriteBool(Context $context, \PHPLLVM\Value $outPtr, bool $value): void
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
}
