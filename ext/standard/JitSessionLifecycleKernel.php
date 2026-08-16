<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\SessionCreateIdRuntime;
use PHPCompiler\JIT\Builtin\SessionStart;
use PHPCompiler\JIT\Builtin\SessionStorageGlobals;
use PHPCompiler\JIT\Builtin\SessionStorageRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\SuperglobalInit;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT LLVM for session lifecycle (#5332, #5750, #6968, #9446, #19896, #21564).
 *
 * Quarantined from lib/JIT/Builtin/SessionLifecycleRuntime —
 * {@see \PHPCompiler\JIT\Builtin\SessionLifecycleRuntime} stays the thin orchestrator.
 *
 * Embed + standalone: honest `__phpc_session_*_apply` bodies (cookie/disk) — no
 * legacy C-symbol forwarder / embed-only simplified stubs (#21564).
 * Replaces lib/AOT/runtime/phpc_session_lifecycle.c. php-src: ext/session/session.c
 * `__phpc_session_generate_new_id` routes through SessionCreateIdJitHelper PHP (#9500).
 */
final class JitSessionLifecycleKernel
{
    private const G_SG_SESSION = 'sg_SESSION';

    public static function ensureLinked(Context $context): void
    {
        SessionStorageGlobals::ensureGlobals($context);
        SessionStorageGlobals::implementEnsureDefaults($context);
        SessionStorageRuntime::ensureLinked($context);
        self::implementGenerateNewId($context);

        self::implementStandaloneRuntime($context);
        self::implementStandaloneWriteClose($context);
        self::implementStandaloneRegenerateId($context);
        self::implementStandaloneDestroy($context);
        self::implementStandaloneAbort($context);
        self::implementStandaloneReset($context);
        self::implementStandaloneUnset($context);
    }

    private static function implementGenerateNewId(Context $context): void
    {
        $fn = $context->lookupFunction('__phpc_session_generate_new_id');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        SessionCreateIdRuntime::ensureRandomIdStringLinked($context);

        $entry = $fn->appendBasicBlock('sgen_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);

        $idStr = $context->builder->call($context->lookupFunction('phpc_session_random_id_string'));
        $isNull = $context->builder->icmp(Builder::INT_EQ, $idStr, $strPtr->constNull());
        $bbEmpty = BasicBlockHelper::append($context, 'sgen_empty');
        $bbCopy = BasicBlockHelper::append($context, 'sgen_copy');
        $bbDone = BasicBlockHelper::append($context, 'sgen_done');
        $context->builder->branchIf($isNull, $bbEmpty, $bbCopy);

        $context->builder->positionAtEnd($bbEmpty);
        self::emitStoreSessionIdLen($context, $zeroI64);
        self::emitNulTerminateIdAt($context, $zeroI64);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCopy);
        self::emitCopyIdStringToGlobals($context, $idStr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    private static function implementStandaloneRuntime(Context $context): void
    {
        $fn = $context->lookupFunction('__phpc_session_start_apply');
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
        $bbCheckHeaders = BasicBlockHelper::append($context, 'ssr_check_headers');
        $bbHeadersFail = BasicBlockHelper::append($context, 'ssr_headers_fail');
        $bbStart = BasicBlockHelper::append($context, 'ssr_start');
        $bbDone = BasicBlockHelper::append($context, 'ssr_done');
        $context->builder->branchIf($isActive, $bbInactive, $bbCheckHeaders);

        $context->builder->positionAtEnd($bbInactive);
        // php-src: session already active → true (+ E_NOTICE); not false.
        SessionStart::emitWriteBool($context, $outPtr, true);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbCheckHeaders);
        $headersSent = $context->builder->call($context->lookupFunction('__phpc_headers_sent'));
        $headersSentNonZero = $context->builder->icmp(Builder::INT_NE, $headersSent, $i32->constInt(0, false));
        $context->builder->branchIf($headersSentNonZero, $bbHeadersFail, $bbStart);

        $context->builder->positionAtEnd($bbHeadersFail);
        SessionStart::emitHeadersSentWarning($context);
        SessionStart::emitWriteBool($context, $outPtr, false);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbStart);
        SessionStorageGlobals::emitCallEnsureDefaults($context);
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $noExistingId = $context->builder->icmp(Builder::INT_EQ, $idLen, $zeroI64);
        $cookieOk = $context->builder->call($context->lookupFunction('phpc_session_apply_incoming_cookie'));
        $cookieFailed = $context->builder->icmp(Builder::INT_EQ, $cookieOk, $i32->constInt(0, false));
        $needNewId = $context->builder->and($cookieFailed, $noExistingId);
        $bbNewId = BasicBlockHelper::append($context, 'ssr_new_id');
        $bbAfterCookie = BasicBlockHelper::append($context, 'ssr_after_cookie');
        $context->builder->branchIf($needNewId, $bbNewId, $bbAfterCookie);

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
        // php-src PHP_FUNCTION(session_regenerate_id) — E_WARNING when inactive (#31444).
        JitBuiltinWarning::emit($context, VmSession::REGENERATE_NO_SESSION_WARNING);
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

    private static function implementStandaloneReset(Context $context): void
    {
        $fn = $context->lookupFunction('__phpc_session_reset_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        self::emitActiveGuardedLifecycle(
            $context,
            $fn,
            static function (Context $context, Value $outPtr): void {
                $context->builder->call($context->lookupFunction('phpc_session_load_from_disk'));
                SessionStart::emitWriteBool($context, $outPtr, true);
            }
        );
    }

    private static function implementStandaloneUnset(Context $context): void
    {
        $fn = $context->lookupFunction('__phpc_session_unset_apply');
        if ($fn->countBasicBlocks() > 0) {
            return;
        }

        self::emitActiveGuardedLifecycle(
            $context,
            $fn,
            static function (Context $context, Value $outPtr): void {
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

    private static function emitEnsureSessionTable(Context $context): void
    {
        // php_session_track_init — always replace with empty HT before load (#26088).
        $fresh = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($fresh, self::sgSessionPtr($context));
        if (isset(SuperglobalInit::$globals['_SESSION'])) {
            $context->builder->store($fresh, SuperglobalInit::$globals['_SESSION']);
        }
    }

    private static function emitCopyIdStringToGlobals(Context $context, Value $idStr): void
    {
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $maxLen = $i64->constInt(VmSession::MAX_ID_LEN, false);

        $newLen = $context->builder->load(
            $context->builder->structGep($idStr, $strMap['length'])
        );
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $newLen, $maxLen);
        $storeLen = $context->builder->select($tooLong, $maxLen, $newLen);
        $context->builder->store($storeLen, SessionStorageGlobals::$idLenGlobal);
        $newBytes = $context->builder->structGep($idStr, $strMap['value']);
        $bufPtr = self::idBufPtr($context);
        $context->intrinsic->memcpy($bufPtr, $newBytes, $storeLen, false);
        $nulPtr = $context->builder->inBoundsGEP($bufPtr, $storeLen);
        $context->builder->store($i8->constInt(0, false), $nulPtr);
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
