<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ErrorReporter;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM entry {@see __phpc_session_start_apply} — bool in caller {@see __value__} out-slot (#1882). */
final class SessionStart
{
    public const RUNTIME_C_SYMBOL = 'phpc_session_start_runtime';

    public static function implement(Context $context): void
    {
        $fn = $context->lookupFunction('__phpc_session_start_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            self::implementStandaloneForwarder($context, $fn);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $zeroI8 = $i8->constInt(0, false);
        $oneI8 = $i8->constInt(1, false);
        $i32 = $context->getTypeFromString('int32');

        $entry = $fn->appendBasicBlock('ss_entry');
        $bbActive = $fn->appendBasicBlock('ss_active');
        $bbCheckHeaders = $fn->appendBasicBlock('ss_check_headers');
        $bbHeadersFail = $fn->appendBasicBlock('ss_headers_fail');
        $bbStart = $fn->appendBasicBlock('ss_start');
        $bbDone = $fn->appendBasicBlock('ss_done');

        $context->builder->positionAtEnd($entry);
        $outPtr = $fn->getParam(0);
        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $context->builder->branchIf($isActive, $bbActive, $bbCheckHeaders);

        $context->builder->positionAtEnd($bbActive);
        self::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckHeaders);
        $headersSent = $context->builder->call($context->lookupFunction('__phpc_headers_sent'));
        $headersSentNonZero = $context->builder->icmp(Builder::INT_NE, $headersSent, $i32->constInt(0, false));
        $context->builder->branchIf($headersSentNonZero, $bbHeadersFail, $bbStart);

        $context->builder->positionAtEnd($bbHeadersFail);
        self::emitHeadersSentWarning($context);
        self::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbStart);
        $context->builder->store($oneI8, SessionStorageGlobals::$activeGlobal);
        self::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function registerRuntimeDeclaration(Context $context): void
    {
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->context->voidType();
        $sig = $context->context->functionType($void, false, $valuePtr);
        $runtimeFn = $context->module->addFunction(self::RUNTIME_C_SYMBOL, $sig);
        $context->registerFunction(self::RUNTIME_C_SYMBOL, $runtimeFn);
    }

    private static function implementStandaloneForwarder(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_forward');
        $context->builder->positionAtEnd($entry);
        $context->builder->call(
            $context->lookupFunction(self::RUNTIME_C_SYMBOL),
            $fn->getParam(0)
        );
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function emitWriteBool(Context $context, Value $outPtr, bool $value): void
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

    public static function emitHeadersSentWarning(Context $context): void
    {
        StringTriggerError::ensureLinked($context);
        $msg = $context->constantFromString(VmSession::HEADERS_SENT_START_WARNING);
        $strMap = $context->structFieldMap['__string__'];
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $msgLen = $context->builder->load(
            $context->builder->structGep($msg, $strMap['length'])
        );
        $msgBytes = $context->builder->structGep($msg, $strMap['value']);
        $msgPtr = $context->builder->pointerCast($msgBytes, $i8p);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msgPtr,
            $context->builder->trunc($msgLen, $context->getTypeFromString('size_t')),
            $i32->constInt(ErrorReporter::E_WARNING, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
    }
}
