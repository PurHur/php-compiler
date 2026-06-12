<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\SuperglobalInit;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM session lifecycle for JIT/AOT (issues #5332, #5750, #6968 phase 2).
 *
 * Replaces lib/AOT/runtime/phpc_session_lifecycle.c. php-src: ext/session/session.c
 */
final class SessionLifecycleRuntime
{
    private const G_SG_SESSION = 'sg_SESSION';

    private const HEX_TABLE = '0123456789abcdef';

    public static function ensureLinked(Context $context): void
    {
        SessionStorageGlobals::ensureGlobals($context);
        SessionStorageGlobals::implementEnsureDefaults($context);
        SessionStorageRuntime::ensureLinked($context);
        self::implementGenerateNewId($context);

        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }

        self::implementStandaloneRuntime($context);
        self::implementStandaloneWriteClose($context);
        self::implementStandaloneRegenerateId($context);
        self::implementStandaloneDestroy($context);
        self::implementStandaloneAbort($context);
    }

    private static function implementGenerateNewId(Context $context): void
    {
        $fn = $context->lookupFunction('__phpc_session_generate_new_id');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('sgen_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $sixteen = $i64->constInt(16, false);
        $thirtyTwo = $i64->constInt(32, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);

        $raw = $context->builder->call($context->lookupFunction('__compiler_random_bytes'), $sixteen);
        $rawNull = $context->builder->icmp(Builder::INT_EQ, $raw, $strPtr->constNull());
        $bbEmpty = BasicBlockHelper::append($context, 'sgen_empty');
        $bbCheckLen = BasicBlockHelper::append($context, 'sgen_check_len');
        $context->builder->branchIf($rawNull, $bbEmpty, $bbCheckLen);

        $context->builder->positionAtEnd($bbCheckLen);
        $strMap = $context->structFieldMap['__string__'];
        $rawLen = $context->builder->load($context->builder->structGep($raw, $strMap['length']));
        $tooShort = $context->builder->icmp(Builder::INT_SLT, $rawLen, $sixteen);
        $bbEncode = BasicBlockHelper::append($context, 'sgen_encode');
        $context->builder->branchIf($tooShort, $bbEmpty, $bbEncode);

        $context->builder->positionAtEnd($bbEmpty);
        self::emitStoreSessionIdLen($context, $zeroI64);
        self::emitNulTerminateIdAt($context, $zeroI64);
        $bbDone = BasicBlockHelper::append($context, 'sgen_done');
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbEncode);
        $i8p = $context->getTypeFromString('int8*');
        $hexBase = $context->builder->pointerCast(self::hexTableGlobal($context), $i8p);
        $rawBytes = $context->builder->structGep($raw, $strMap['value']);
        $iSlot = $context->builder->alloca($i64, 1, 'sgen_i');
        $context->builder->store($zeroI64, $iSlot);
        $loopHead = BasicBlockHelper::append($context, 'sgen_loop_head');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $loopDone = $context->builder->icmp(Builder::INT_SGE, $i, $thirtyTwo);
        $loopBody = BasicBlockHelper::append($context, 'sgen_loop_body');
        $context->builder->branchIf($loopDone, $bbDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $byteIdx = $context->builder->lshr($i, $oneI64);
        $bytePtr = $context->builder->inBoundsGEP($rawBytes, $byteIdx);
        $byte = $context->builder->load($bytePtr);
        $isLow = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->and($i, $oneI64),
            $zeroI64
        );
        $highNibble = $context->builder->lshr($byte, $i8->constInt(4, false));
        $lowNibble = $context->builder->and($byte, $i8->constInt(0x0f, false));
        $nibble = $context->builder->select($isLow, $lowNibble, $highNibble);
        $hexPtr = $context->builder->inBoundsGEP(
            $hexBase,
            $context->builder->zext($nibble, $i64)
        );
        $hexChar = $context->builder->load($hexPtr);
        $idPtr = self::idBufPtr($context);
        $outPtr = $context->builder->inBoundsGEP($idPtr, $i);
        $context->builder->store($hexChar, $outPtr);
        $context->builder->store($context->builder->add($i, $oneI64), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($bbDone);
        self::emitStoreSessionIdLen($context, $thirtyTwo);
        self::emitNulTerminateIdAt($context, $thirtyTwo);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementStandaloneRuntime(Context $context): void
    {
        $fn = $context->lookupFunction(SessionStart::RUNTIME_C_SYMBOL);
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('ssr_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zeroI8 = $i8->constInt(0, false);
        $oneI8 = $i8->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $outPtr = $fn->getParam(0);

        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $bbInactive = BasicBlockHelper::append($context, 'ssr_inactive');
        $bbStart = BasicBlockHelper::append($context, 'ssr_start');
        $bbDone = BasicBlockHelper::append($context, 'ssr_done');
        $context->builder->branchIf($isActive, $bbInactive, $bbStart);

        $context->builder->positionAtEnd($bbInactive);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbStart);
        self::emitEnsureDefaultSessionName($context);
        $cookieOk = $context->builder->call($context->lookupFunction('phpc_session_apply_incoming_cookie'));
        $cookieFailed = $context->builder->icmp(Builder::INT_EQ, $cookieOk, $i32->constInt(0, false));
        $bbNewId = BasicBlockHelper::append($context, 'ssr_new_id');
        $bbAfterCookie = BasicBlockHelper::append($context, 'ssr_after_cookie');
        $context->builder->branchIf($cookieFailed, $bbNewId, $bbAfterCookie);

        $context->builder->positionAtEnd($bbNewId);
        $context->builder->call($context->lookupFunction('__phpc_session_generate_new_id'));
        $context->builder->call($context->lookupFunction('phpc_session_emit_setcookie'));
        $context->builder->branch($bbAfterCookie);

        $context->builder->positionAtEnd($bbAfterCookie);
        self::emitEnsureSessionTable($context);
        $context->builder->call($context->lookupFunction('phpc_session_load_from_disk'));
        $context->builder->store($oneI8, SessionStorageGlobals::$activeGlobal);
        SessionStart::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementStandaloneWriteClose(Context $context): void
    {
        $fn = $context->lookupFunction('__phpc_session_write_close_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        self::emitActiveGuardedLifecycle(
            $context,
            $fn,
            static function (Context $context, Value $outPtr): void {
                $i8 = $context->getTypeFromString('int8');
                $context->builder->call($context->lookupFunction('phpc_session_save_to_disk'));
                $context->builder->store($i8->constInt(0, false), SessionStorageGlobals::$activeGlobal);
                SessionStart::emitWriteBool($context, $outPtr, true);
            }
        );
    }

    private static function implementStandaloneRegenerateId(Context $context): void
    {
        $fn = $context->lookupFunction('__phpc_session_regenerate_id_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        $entry = $fn->appendBasicBlock('srid_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $zeroI8 = $i8->constInt(0, false);
        $outPtr = $fn->getParam(0);
        $deleteOld = $fn->getParam(1);

        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $bbInactive = BasicBlockHelper::append($context, 'srid_inactive');
        $bbRotate = BasicBlockHelper::append($context, 'srid_rotate');
        $bbDone = BasicBlockHelper::append($context, 'srid_done');
        $context->builder->branchIf($isActive, $bbRotate, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbRotate);
        $context->builder->call($context->lookupFunction('phpc_session_save_to_disk'));
        $shouldUnlink = $context->builder->icmp(Builder::INT_NE, $deleteOld, $zeroI8);
        $bbUnlink = BasicBlockHelper::append($context, 'srid_unlink');
        $bbAfterUnlink = BasicBlockHelper::append($context, 'srid_after_unlink');
        $context->builder->branchIf($shouldUnlink, $bbUnlink, $bbAfterUnlink);

        $context->builder->positionAtEnd($bbUnlink);
        $context->builder->call($context->lookupFunction('phpc_session_unlink_file'));
        $context->builder->branch($bbAfterUnlink);

        $context->builder->positionAtEnd($bbAfterUnlink);
        $context->builder->call($context->lookupFunction('__phpc_session_generate_new_id'));
        $context->builder->call($context->lookupFunction('phpc_session_emit_setcookie'));
        SessionStart::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementStandaloneAbort(Context $context): void
    {
        $fn = $context->lookupFunction('__phpc_session_abort_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        self::emitActiveGuardedLifecycle(
            $context,
            $fn,
            static function (Context $context, Value $outPtr): void {
                $i8 = $context->getTypeFromString('int8');
                $context->builder->store($i8->constInt(0, false), SessionStorageGlobals::$activeGlobal);
                SessionStart::emitWriteBool($context, $outPtr, true);
            }
        );
    }

    private static function implementStandaloneDestroy(Context $context): void
    {
        $fn = $context->lookupFunction('__phpc_session_destroy_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        self::emitActiveGuardedLifecycle(
            $context,
            $fn,
            static function (Context $context, Value $outPtr): void {
                $i8 = $context->getTypeFromString('int8');
                $i64 = $context->getTypeFromString('int64');
                $htPtr = $context->getTypeFromString('__hashtable__*');
                $context->builder->call($context->lookupFunction('phpc_session_unlink_file'));
                $context->builder->store($i8->constInt(0, false), SessionStorageGlobals::$activeGlobal);
                self::emitStoreSessionIdLen($context, $i64->constInt(0, false));
                self::emitNulTerminateIdAt($context, $i64->constInt(0, false));
                $empty = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
                $sgSession = self::sgSessionPtr($context);
                $context->builder->store($empty, $sgSession);
                if (isset(SuperglobalInit::$globals['_SESSION'])) {
                    $context->builder->store($empty, SuperglobalInit::$globals['_SESSION']);
                }
                SessionStart::emitWriteBool($context, $outPtr, true);
            }
        );
    }

    /**
     * @param callable(Context, Value): void $activeBody
     */
    private static function emitActiveGuardedLifecycle(Context $context, LlvmFunction $fn, callable $activeBody): void
    {
        $entry = $fn->appendBasicBlock('slc_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $zeroI8 = $i8->constInt(0, false);
        $outPtr = $fn->getParam(0);

        $active = $context->builder->load(SessionStorageGlobals::$activeGlobal);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $zeroI8);
        $bbInactive = BasicBlockHelper::append($context, 'slc_inactive');
        $bbActive = BasicBlockHelper::append($context, 'slc_active');
        $bbDone = BasicBlockHelper::append($context, 'slc_done');
        $context->builder->branchIf($isActive, $bbActive, $bbInactive);

        $context->builder->positionAtEnd($bbInactive);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbActive);
        $activeBody($context, $outPtr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function emitEnsureDefaultSessionName(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);
        $defaultLen = $i64->constInt(\strlen(VmSession::DEFAULT_NAME), false);

        $nameLen = $context->builder->load(SessionStorageGlobals::$nameLenGlobal);
        $needsSeed = $context->builder->icmp(Builder::INT_SLE, $nameLen, $zeroI64);
        $fn = BasicBlockHelper::parentFunction($context);
        $bbSeed = $fn->appendBasicBlock('slc_seed_name_'.spl_object_id($context));
        $bbAfter = $fn->appendBasicBlock('slc_after_seed_'.spl_object_id($context));
        $context->builder->branchIf($needsSeed, $bbSeed, $bbAfter);

        $context->builder->positionAtEnd($bbSeed);
        $context->builder->store($defaultLen, SessionStorageGlobals::$nameLenGlobal);
        $bufPtr = $context->builder->inBoundsGEP(
            SessionStorageGlobals::$nameBufGlobal,
            $i32->constInt(0, false),
            $zeroI64
        );
        foreach (str_split(VmSession::DEFAULT_NAME) as $i => $ch) {
            $charPtr = $context->builder->inBoundsGEP($bufPtr, $i64->constInt($i, false));
            $context->builder->store($i8->constInt(\ord($ch), false), $charPtr);
        }
        $nulPtr = $context->builder->inBoundsGEP($bufPtr, $defaultLen);
        $context->builder->store($i8->constInt(0, false), $nulPtr);
        $context->builder->branch($bbAfter);
        $context->builder->positionAtEnd($bbAfter);
    }

    private static function emitEnsureSessionTable(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sgSession = self::sgSessionPtr($context);
        $current = $context->builder->load($sgSession);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $current, $htPtr->constNull());
        $fn = BasicBlockHelper::parentFunction($context);
        $bbAlloc = $fn->appendBasicBlock('slc_alloc_session_'.spl_object_id($context));
        $bbAfter = $fn->appendBasicBlock('slc_after_alloc_'.spl_object_id($context));
        $context->builder->branchIf($isNull, $bbAlloc, $bbAfter);

        $context->builder->positionAtEnd($bbAlloc);
        $fresh = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($fresh, $sgSession);
        $context->builder->branch($bbAfter);
        $context->builder->positionAtEnd($bbAfter);
    }

    private static function emitStoreSessionIdLen(Context $context, Value $len): void
    {
        $context->builder->store($len, SessionStorageGlobals::$idLenGlobal);
    }

    private static function emitNulTerminateIdAt(Context $context, Value $len): void
    {
        $i8 = $context->getTypeFromString('int8');
        $idPtr = self::idBufPtr($context);
        $nulPtr = $context->builder->inBoundsGEP($idPtr, $len);
        $context->builder->store($i8->constInt(0, false), $nulPtr);
    }

    private static function idBufPtr(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->inBoundsGEP(
            SessionStorageGlobals::$idBufGlobal,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
    }

    private static function hexTableGlobal(Context $context): Value
    {
        return $context->constantFromString(self::HEX_TABLE);
    }

    private static function sgSessionPtr(Context $context): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $global = $context->module->getNamedGlobal(self::G_SG_SESSION);
        if (null === $global) {
            $global = $context->module->addGlobal($htPtr, self::G_SG_SESSION);
            $global->setInitializer($htPtr->constNull());
        }

        return $context->builder->pointerCast($global, $htPtr->pointerType(0));
    }
}
