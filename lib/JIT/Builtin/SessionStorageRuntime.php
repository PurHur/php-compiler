<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for session file I/O via SessionStorageJitHelper PHP (#9495, #12938).
 *
 * JIT embed and AOT standalone compile {@see \PHPCompiler\ext\standard\SessionStorageJitHelper}; thin LLVM
 * bridges forward the ABI. php-src: ext/session/mod_files.c
 */
final class SessionStorageRuntime
{
    private const HELPER_PATH = '/ext/standard/SessionStorageJitHelper.php';

    private const G_SG_SESSION = 'sg_SESSION';

    private const G_SG_COOKIE = 'sg_COOKIE';

    private const LOAD_FROM_DISK = 'PHPCompiler\\ext\\standard\\SessionStorageJitHelper::loadFromDisk';

    private const SAVE_TO_DISK = 'PHPCompiler\\ext\\standard\\SessionStorageJitHelper::saveToDisk';

    private const UNLINK_FILE = 'PHPCompiler\\ext\\standard\\SessionStorageJitHelper::unlinkFile';

    private const READ_COOKIE_ID = 'PHPCompiler\\ext\\standard\\SessionStorageJitHelper::readCookieId';

    private const MERGE_HASHTABLES = 'PHPCompiler\\ext\\standard\\SessionStorageJitHelper::mergeHashTables';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::LOAD_FROM_DISK,
        self::SAVE_TO_DISK,
        self::UNLINK_FILE,
        self::READ_COOKIE_ID,
        self::MERGE_HASHTABLES,
    ];

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        'phpc_session_load_from_disk',
        'phpc_session_save_to_disk',
        'phpc_session_unlink_file',
        'phpc_session_apply_incoming_cookie',
        'phpc_session_emit_setcookie',
    ];

    public static function ensureLinked(Context $context): void
    {
        SessionStorageGlobals::ensureGlobals($context);
        PendingHeadersRuntime::ensureLinked($context);
        StringUnserialize::ensureLinked($context);
        StringSerialize::ensureLinked($context);
        StringFileGetContents::implement($context);
        StringFilePutContents::implement($context);
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::ensureLinked($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_session_load_from_disk');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::ensureExternGlobals($context);
        self::ensureJitHelperCompiled($context);
        self::implementIfMissing($context, 'phpc_session_merge_hashtable', self::implementMergeBridge(...));
        self::implementIfMissing($context, 'phpc_session_load_from_disk', self::implementLoadBridge(...));
        self::implementIfMissing($context, 'phpc_session_save_to_disk', self::implementSaveBridge(...));
        self::implementIfMissing($context, 'phpc_session_unlink_file', self::implementUnlinkBridge(...));
        self::implementIfMissing($context, 'phpc_session_apply_incoming_cookie', self::implementApplyCookieBridge(...));
        self::implementIfMissing($context, 'phpc_session_emit_setcookie', self::implementEmitSetcookieBridge(...));
        self::registerLinkedRuntime($context);
        $context->builder->clearInsertionPosition();
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $void = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $fn = match ($name) {
            'phpc_session_merge_hashtable' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $htPtr)
            ),
            'phpc_session_apply_incoming_cookie' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false)
            ),
            default => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false)
            ),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function implementMergeBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_merge_bridge_entry');
        $context->builder->positionAtEnd($entry);
        JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::MERGE_HASHTABLES),
            [$fn->getParam(0), $fn->getParam(1)]
        );
        $context->builder->returnVoid();
    }

    private static function implementLoadBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_load_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $hasId = $context->builder->icmp(Builder::INT_SGT, $idLen, $i64->constInt(0, false));
        $bbDone = BasicBlockHelper::append($context, 'ss_load_bridge_done');
        $bbWork = BasicBlockHelper::append($context, 'ss_load_bridge_work');
        $context->builder->branchIf($hasId, $bbWork, $bbDone);

        $context->builder->positionAtEnd($bbWork);
        $sessionId = self::bufferToString($context, SessionStorageGlobals::$idBufGlobal, $idLen);
        $sgSession = self::sgSessionPtr($context);
        $dest = $context->builder->load($sgSession);
        $destNull = $context->builder->icmp(Builder::INT_EQ, $dest, $htPtr->constNull());
        $bbAlloc = BasicBlockHelper::append($context, 'ss_load_bridge_alloc');
        $bbCall = BasicBlockHelper::append($context, 'ss_load_bridge_call');
        $context->builder->branchIf($destNull, $bbAlloc, $bbCall);

        $context->builder->positionAtEnd($bbAlloc);
        $fresh = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($fresh, $sgSession);
        $context->builder->branch($bbCall);

        $context->builder->positionAtEnd($bbCall);
        $destHt = $context->builder->load($sgSession);
        JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::LOAD_FROM_DISK),
            [$sessionId, $destHt]
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function implementSaveBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_save_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $session = $context->builder->load(self::sgSessionPtr($context));
        $canSave = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $idLen, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $session, $htPtr->constNull())
        );
        $bbDone = BasicBlockHelper::append($context, 'ss_save_bridge_done');
        $bbWork = BasicBlockHelper::append($context, 'ss_save_bridge_work');
        $context->builder->branchIf($canSave, $bbWork, $bbDone);

        $context->builder->positionAtEnd($bbWork);
        $sessionId = self::bufferToString($context, SessionStorageGlobals::$idBufGlobal, $idLen);
        JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::SAVE_TO_DISK),
            [$sessionId, $session]
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function implementUnlinkBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_unlink_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $hasId = $context->builder->icmp(Builder::INT_SGT, $idLen, $i64->constInt(0, false));
        $bbDone = BasicBlockHelper::append($context, 'ss_unlink_bridge_done');
        $bbWork = BasicBlockHelper::append($context, 'ss_unlink_bridge_work');
        $context->builder->branchIf($hasId, $bbWork, $bbDone);

        $context->builder->positionAtEnd($bbWork);
        $sessionId = self::bufferToString($context, SessionStorageGlobals::$idBufGlobal, $idLen);
        JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::UNLINK_FILE),
            [$sessionId]
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function implementApplyCookieBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_cookie_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $nameLen = $context->builder->load(SessionStorageGlobals::$nameLenGlobal);
        $hasName = $context->builder->icmp(Builder::INT_SGT, $nameLen, $i64->constInt(0, false));
        $bbFail = BasicBlockHelper::append($context, 'ss_cookie_bridge_fail');
        $bbLookup = BasicBlockHelper::append($context, 'ss_cookie_bridge_lookup');
        $context->builder->branchIf($hasName, $bbLookup, $bbFail);

        $context->builder->positionAtEnd($bbLookup);
        $cookies = $context->builder->load(self::sgCookiePtr($context));
        $nameStr = self::bufferToString($context, SessionStorageGlobals::$nameBufGlobal, $nameLen);
        $idStr = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::READ_COOKIE_ID),
            [$nameStr, $cookies]
        );
        $strMap = $context->structFieldMap['__string__'];
        $idLen = $context->builder->load($context->builder->structGep($idStr, $strMap['length']));
        $hasId = $context->builder->icmp(Builder::INT_SGT, $idLen, $i64->constInt(0, false));
        $bbStore = BasicBlockHelper::append($context, 'ss_cookie_bridge_store');
        $context->builder->branchIf($hasId, $bbStore, $bbFail);

        $context->builder->positionAtEnd($bbStore);
        self::emitCopyIdStringToGlobals($context, $idStr);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($bbFail);
        $context->builder->returnValue($zeroI32);
    }

    private static function implementEmitSetcookieBridge(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_setcookie_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $hasId = $context->builder->icmp(Builder::INT_SGT, $idLen, $i64->constInt(0, false));
        $bbDone = BasicBlockHelper::append($context, 'ss_setcookie_bridge_done');
        $bbWork = BasicBlockHelper::append($context, 'ss_setcookie_bridge_work');
        $context->builder->branchIf($hasId, $bbWork, $bbDone);

        $context->builder->positionAtEnd($bbWork);
        $nameStr = self::bufferToString(
            $context,
            SessionStorageGlobals::$nameBufGlobal,
            $context->builder->load(SessionStorageGlobals::$nameLenGlobal)
        );
        $valueStr = self::bufferToString($context, SessionStorageGlobals::$idBufGlobal, $idLen);
        $pathStr = self::literalString($context, '/');
        $context->builder->call(
            $context->lookupFunction('__phpc_setcookie_add'),
            $nameStr,
            $valueStr,
            $i64->constInt(0, false),
            $pathStr,
            $strPtr->constNull(),
            $i32->constInt(0, false),
            $i32->constInt(0, false),
            $strPtr->constNull(),
            $i32->constInt(0, false)
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function emitCopyIdStringToGlobals(Context $context, Value $idStr): void
    {
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
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

    private static function bufferToString(Context $context, Value $bufGlobal, Value $len): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $bufPtr = $context->builder->inBoundsGEP(
            $bufGlobal,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $cstr = $context->builder->pointerCast($bufPtr, $i8p);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $cstr
        );
    }

    private static function literalString(Context $context, string $text): Value
    {
        return $context->builder->load($context->constantStringFromString($text));
    }

    private static function sgSessionPtr(Context $context): Value
    {
        return self::externalGlobalPtr($context, self::G_SG_SESSION, $context->getTypeFromString('__hashtable__*'));
    }

    private static function sgCookiePtr(Context $context): Value
    {
        return self::externalGlobalPtr($context, self::G_SG_COOKIE, $context->getTypeFromString('__hashtable__*'));
    }

    private static function externalGlobalPtr(Context $context, string $name, $llvmType): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            $global = $context->module->addGlobal($llvmType, $name);
            $global->setInitializer($llvmType->constNull());
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function ensureExternGlobals(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        foreach ([self::G_SG_SESSION, self::G_SG_COOKIE] as $name) {
            $existing = $context->module->getNamedGlobal($name);
            if (null === $existing) {
                $g = $context->module->addGlobal($htPtr, $name);
                $g->setInitializer($htPtr->constNull());
            }
        }
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::ensureExternal(
            $context,
            '__hashtable__alloc',
            $context->context->functionType($htPtr, false)
        );
        self::ensureExternal(
            $context,
            '__string__init',
            $context->context->functionType(
                $context->getTypeFromString('__string__*'),
                false,
                $context->getTypeFromString('int64'),
                $context->getTypeFromString('int8*')
            )
        );
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after SessionStorageJitHelper compile (#9495)');
        }

        return $fn;
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'SessionStorageJitHelper.php');
            if (null === $block) {
                throw new \LogicException('SessionStorageJitHelper.php parseAndCompile failed (#9495)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT (#9495)');
            }
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after SessionStorageRuntime bridge (#9495)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
