<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\PendingHeadersRuntime;
use PHPCompiler\JIT\Builtin\SessionStorageGlobals;
use PHPCompiler\JIT\Builtin\StringFileGetContents;
use PHPCompiler\JIT\Builtin\StringFilePutContents;
use PHPCompiler\JIT\Builtin\StringGetenv;
use PHPCompiler\JIT\Builtin\StringSerialize;
use PHPCompiler\JIT\Builtin\StringUnserialize;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT NestedJIT bridges for session file I/O (#9495, #19882, #23284).
 *
 * Quarantined from lib/JIT/Builtin/SessionStorageRuntime — {@see \PHPCompiler\JIT\Builtin\SessionStorageRuntime}
 * stays the thin orchestrator.
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer EnvLocal #23211 / ScopeBuiltin #23261).
 *
 * SSOT: {@see SessionStorageJitHelper}
 * php-src: ext/session/mod_files.c
 */
final class JitSessionStorageKernel
{
    private const HELPER_PATH = '/ext/standard/SessionStorageJitHelper.php';

    private const G_SG_SESSION = 'sg_SESSION';

    private const G_SG_COOKIE = 'sg_COOKIE';

    private const LOAD_FROM_PATH = 'PHPCompiler\\ext\\standard\\SessionStorageJitHelper::loadFromPath';

    private const SAVE_TO_PATH = 'PHPCompiler\\ext\\standard\\SessionStorageJitHelper::saveToPath';

    private const UNLINK_PATH = 'PHPCompiler\\ext\\standard\\SessionStorageJitHelper::unlinkPath';

    private const MERGE_HASHTABLES = 'PHPCompiler\\ext\\standard\\SessionStorageJitHelper::mergeHashTables';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        // Load/save are LLVM wire I/O (#21900). Keep unlink + merge NestedJIT.
        self::UNLINK_PATH,
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
        // Cookie apply + session path I/O use libc getenv (#21900).
        LibcExtern::register($context);
        // Module-local strncmp after LibcExtern always-on drop (#31839).
        LibcExtern::ensureStrncmp($context);
        // Module-local memcpy(3) after LibcExtern always-on drop (#31885).
        LibcExtern::ensureMemcpyDecl($context);
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
        LibcExtern::register($context);
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
        $pathStr = self::emitSessionFilePathString($context, $idLen);
        self::emitSessionWireLoadFromPath($context, $pathStr, $destHt);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function implementSaveBridge(Context $context, LlvmFunction $fn): void
    {
        LibcExtern::register($context);
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
        self::emitEnsureSessionDir($context);
        $pathStr = self::emitSessionFilePathString($context, $idLen);
        // LLVM wire encode for string-key scalars — NestedJIT encode sees strlen=0 on
        // Variable::toString() from HashTable::find (#21900).
        self::emitSessionWireSaveToPath($context, $session, $pathStr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function implementUnlinkBridge(Context $context, LlvmFunction $fn): void
    {
        LibcExtern::register($context);
        $entry = $fn->appendBasicBlock('ss_unlink_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $hasId = $context->builder->icmp(Builder::INT_SGT, $idLen, $i64->constInt(0, false));
        $bbDone = BasicBlockHelper::append($context, 'ss_unlink_bridge_done');
        $bbWork = BasicBlockHelper::append($context, 'ss_unlink_bridge_work');
        $context->builder->branchIf($hasId, $bbWork, $bbDone);

        $context->builder->positionAtEnd($bbWork);
        $pathStr = self::emitSessionFilePathString($context, $idLen);
        JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::UNLINK_PATH),
            [$pathStr]
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    /**
     * Load php session wire (multi-key s/i/b/N) via libc into Hashtable (#21900 / #21922).
     */
    private static function emitSessionWireLoadFromPath(Context $context, Value $pathStr, Value $destHt): void
    {
        LibcExtern::register($context);
        // Module-local open/close/read after LibcExtern always-on drop (#31817).
        LibcExtern::ensurePosixFd($context);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strMap = $context->structFieldMap['__string__'];

        $pathLen = $context->builder->load($context->builder->structGep($pathStr, $strMap['length']));
        $pathBytes = $context->builder->structGep($pathStr, $strMap['value']);
        $pathC = $context->builder->alloca($i8->arrayType(640));
        $pathCPtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($pathC, $i32->constInt(0, false), $i64->constInt(0, false)),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $pathCPtr,
            $pathBytes,
            $context->builder->intCast($pathLen, $sizeT)
        );
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($pathCPtr, $pathLen)
        );

        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $pathCPtr,
            $i32->constInt(0, false),
            $i32->constInt(0, false)
        );
        $okFd = $context->builder->icmp(Builder::INT_SGE, $fd, $i32->constInt(0, false));
        $bbRead = BasicBlockHelper::append($context, 'ss_wire_load_read');
        $bbDone = BasicBlockHelper::append($context, 'ss_wire_load_done');
        $context->builder->branchIf($okFd, $bbRead, $bbDone);

        $context->builder->positionAtEnd($bbRead);
        $bufType = $i8->arrayType(4096);
        $buf = $context->builder->alloca($bufType);
        $bufPtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($buf, $i32->constInt(0, false), $i64->constInt(0, false)),
            $i8p
        );
        $nread = $context->builder->call(
            $context->lookupFunction('read'),
            $fd,
            $bufPtr,
            $sizeT->constInt(4095, false)
        );
        $context->builder->call($context->lookupFunction('close'), $fd);
        $hasData = $context->builder->icmp(Builder::INT_SGT, $nread, $i64->constInt(0, false));
        $bbLoopInit = BasicBlockHelper::append($context, 'ss_wire_load_loop_init');
        $context->builder->branchIf($hasData, $bbLoopInit, $bbDone);

        $context->builder->positionAtEnd($bbLoopInit);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($bufPtr, $nread)
        );
        $curSlot = $context->builder->alloca($i8p);
        $context->builder->store($bufPtr, $curSlot);
        $bbLoop = BasicBlockHelper::append($context, 'ss_wire_load_loop');
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbLoop);
        $cur = $context->builder->load($curSlot);
        $pipe = $context->builder->call(
            $context->lookupFunction('strchr'),
            $cur,
            $i32->constInt(0x7c, false)
        );
        $hasPipe = $context->builder->icmp(Builder::INT_NE, $pipe, $i8p->constNull());
        $bbHavePipe = BasicBlockHelper::append($context, 'ss_wire_load_have_pipe');
        $context->builder->branchIf($hasPipe, $bbHavePipe, $bbDone);

        $context->builder->positionAtEnd($bbHavePipe);
        $keyLen = $context->builder->sub(
            $context->builder->ptrToInt($pipe, $i64),
            $context->builder->ptrToInt($cur, $i64)
        );
        $keyOk = $context->builder->icmp(Builder::INT_SGT, $keyLen, $i64->constInt(0, false));
        $bbKeyOk = BasicBlockHelper::append($context, 'ss_wire_load_key_ok');
        $context->builder->branchIf($keyOk, $bbKeyOk, $bbDone);

        $context->builder->positionAtEnd($bbKeyOk);
        $keyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $keyLen,
            $cur
        );
        $afterPipe = $context->builder->inBoundsGEP($pipe, $i64->constInt(1, false));
        $tag = $context->builder->load($afterPipe);
        $bbStr = BasicBlockHelper::append($context, 'ss_wire_load_str');
        $bbInt = BasicBlockHelper::append($context, 'ss_wire_load_int');
        $bbBool = BasicBlockHelper::append($context, 'ss_wire_load_bool');
        $bbNull = BasicBlockHelper::append($context, 'ss_wire_load_null');
        $bbAdvance = BasicBlockHelper::append($context, 'ss_wire_load_advance');
        $bbAfterStrTag = BasicBlockHelper::append($context, 'ss_wire_load_after_s');
        $bbAfterIntTag = BasicBlockHelper::append($context, 'ss_wire_load_after_i');
        $bbAfterBoolTag = BasicBlockHelper::append($context, 'ss_wire_load_after_b');
        $isS = $context->builder->icmp(Builder::INT_EQ, $tag, $i8->constInt(0x73, false));
        $context->builder->branchIf($isS, $bbStr, $bbAfterStrTag);

        // s:N:"val";
        $context->builder->positionAtEnd($bbStr);
        $c1 = $context->builder->load($context->builder->inBoundsGEP($afterPipe, $i64->constInt(1, false)));
        $okSColon = $context->builder->icmp(Builder::INT_EQ, $c1, $i8->constInt(0x3a, false));
        $bbSNum = BasicBlockHelper::append($context, 'ss_wire_load_s_num');
        $context->builder->branchIf($okSColon, $bbSNum, $bbDone);
        $context->builder->positionAtEnd($bbSNum);
        $numStart = $context->builder->inBoundsGEP($afterPipe, $i64->constInt(2, false));
        $endPtr = $context->builder->alloca($i8p);
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $valLen = $context->builder->call(
            $context->lookupFunction('strtol'),
            $numStart,
            $endPtr,
            $i32->constInt(10, false)
        );
        $afterNum = $context->builder->load($endPtr);
        $colon2 = $context->builder->load($afterNum);
        $quote = $context->builder->load($context->builder->inBoundsGEP($afterNum, $i64->constInt(1, false)));
        $okMid = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $colon2, $i8->constInt(0x3a, false)),
            $context->builder->icmp(Builder::INT_EQ, $quote, $i8->constInt(0x22, false))
        );
        $bbSVal = BasicBlockHelper::append($context, 'ss_wire_load_s_val');
        $context->builder->branchIf($okMid, $bbSVal, $bbDone);
        $context->builder->positionAtEnd($bbSVal);
        $valStart = $context->builder->inBoundsGEP($afterNum, $i64->constInt(2, false));
        $valStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $valLen,
            $valStart
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $destHt,
            $keyStr,
            $valStr
        );
        // advance past val + '"' + ';'
        $nextS = $context->builder->inBoundsGEP(
            $valStart,
            $context->builder->add($valLen, $i64->constInt(2, false))
        );
        $context->builder->store($nextS, $curSlot);
        $context->builder->branch($bbAdvance);

        $context->builder->positionAtEnd($bbAfterStrTag);
        $isI = $context->builder->icmp(Builder::INT_EQ, $tag, $i8->constInt(0x69, false));
        $context->builder->branchIf($isI, $bbInt, $bbAfterIntTag);

        // i:N;
        $context->builder->positionAtEnd($bbInt);
        $c1i = $context->builder->load($context->builder->inBoundsGEP($afterPipe, $i64->constInt(1, false)));
        $okIColon = $context->builder->icmp(Builder::INT_EQ, $c1i, $i8->constInt(0x3a, false));
        $bbINum = BasicBlockHelper::append($context, 'ss_wire_load_i_num');
        $context->builder->branchIf($okIColon, $bbINum, $bbDone);
        $context->builder->positionAtEnd($bbINum);
        $iStart = $context->builder->inBoundsGEP($afterPipe, $i64->constInt(2, false));
        $iEndPtr = $context->builder->alloca($i8p);
        // strtol(3) via LibcExtern::ensureStrtolDecl after always-on drop (#31988).
        LibcExtern::ensureStrtolDecl($context);
        $iVal = $context->builder->call(
            $context->lookupFunction('strtol'),
            $iStart,
            $iEndPtr,
            $i32->constInt(10, false)
        );
        $afterI = $context->builder->load($iEndPtr);
        $semiI = $context->builder->load($afterI);
        $okSemiI = $context->builder->icmp(Builder::INT_EQ, $semiI, $i8->constInt(0x3b, false));
        $bbISet = BasicBlockHelper::append($context, 'ss_wire_load_i_set');
        $context->builder->branchIf($okSemiI, $bbISet, $bbDone);
        $context->builder->positionAtEnd($bbISet);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $destHt,
            $keyStr,
            $iVal
        );
        $context->builder->store(
            $context->builder->inBoundsGEP($afterI, $i64->constInt(1, false)),
            $curSlot
        );
        $context->builder->branch($bbAdvance);

        $context->builder->positionAtEnd($bbAfterIntTag);
        $isB = $context->builder->icmp(Builder::INT_EQ, $tag, $i8->constInt(0x62, false));
        $context->builder->branchIf($isB, $bbBool, $bbAfterBoolTag);

        // b:0; / b:1;
        $context->builder->positionAtEnd($bbBool);
        $c1b = $context->builder->load($context->builder->inBoundsGEP($afterPipe, $i64->constInt(1, false)));
        $digit = $context->builder->load($context->builder->inBoundsGEP($afterPipe, $i64->constInt(2, false)));
        $semiB = $context->builder->load($context->builder->inBoundsGEP($afterPipe, $i64->constInt(3, false)));
        $okB = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $c1b, $i8->constInt(0x3a, false)),
            $context->builder->icmp(Builder::INT_EQ, $semiB, $i8->constInt(0x3b, false))
        );
        $bbBSet = BasicBlockHelper::append($context, 'ss_wire_load_b_set');
        $context->builder->branchIf($okB, $bbBSet, $bbDone);
        $context->builder->positionAtEnd($bbBSet);
        $boolVal = $context->builder->icmp(Builder::INT_NE, $digit, $i8->constInt(0x30, false));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $destHt,
            $keyStr,
            $boolVal
        );
        $context->builder->store(
            $context->builder->inBoundsGEP($afterPipe, $i64->constInt(4, false)),
            $curSlot
        );
        $context->builder->branch($bbAdvance);

        $context->builder->positionAtEnd($bbAfterBoolTag);
        $isN = $context->builder->icmp(Builder::INT_EQ, $tag, $i8->constInt(0x4e, false));
        $context->builder->branchIf($isN, $bbNull, $bbDone);

        // N;
        $context->builder->positionAtEnd($bbNull);
        $semiN = $context->builder->load($context->builder->inBoundsGEP($afterPipe, $i64->constInt(1, false)));
        $okN = $context->builder->icmp(Builder::INT_EQ, $semiN, $i8->constInt(0x3b, false));
        $bbNSet = BasicBlockHelper::append($context, 'ss_wire_load_n_set');
        $context->builder->branchIf($okN, $bbNSet, $bbDone);
        $context->builder->positionAtEnd($bbNSet);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyNull'),
            $destHt,
            $keyStr
        );
        $context->builder->store(
            $context->builder->inBoundsGEP($afterPipe, $i64->constInt(2, false)),
            $curSlot
        );
        $context->builder->branch($bbAdvance);

        $context->builder->positionAtEnd($bbAdvance);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbDone);
    }

    /**
     * Resolve PHP_COMPILER_SESSION_DIR (libc getenv) + sess_<id> into a __string__* (#21900).
     */
    private static function emitSessionFilePathString(Context $context, Value $idLen): Value
    {
        StringGetenv::ensureLibcGetenv($context);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $pathBufType = $i8->arrayType(640);
        $pathBuf = $context->builder->alloca($pathBufType);
        $pathPtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($pathBuf, $i32->constInt(0, false), $i64->constInt(0, false)),
            $i8p
        );
        $dirKey = $context->builder->pointerCast(
            $context->constantFromString('PHP_COMPILER_SESSION_DIR'),
            $i8p
        );
        $dirEnv = $context->builder->call($context->lookupFunction('getenv'), $dirKey);
        $dirDefault = $context->builder->pointerCast(
            $context->constantFromString('/var/lib/php/sessions'),
            $i8p
        );
        $dirNull = $context->builder->icmp(Builder::INT_EQ, $dirEnv, $i8p->constNull());
        $dirEmpty = $context->builder->and(
            $context->builder->icmp(Builder::INT_NE, $dirEnv, $i8p->constNull()),
            $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($dirEnv),
                $i8->constInt(0, false)
            )
        );
        $dirMissing = $context->builder->or($dirNull, $dirEmpty);
        $dir = $context->builder->select($dirMissing, $dirDefault, $dirEnv);
        $idPtr = $context->builder->pointerCast(self::idBufPtr($context), $i8p);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString('%s/sess_%.*s'),
            $i8p
        );
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $pathPtr,
            $sizeT->constInt(640, false),
            $fmt,
            $dir,
            $context->builder->trunc($idLen, $i32),
            $idPtr
        );
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $pathLen = $context->builder->call($context->lookupFunction('strlen'), $pathPtr);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->intCast($pathLen, $i64),
            $pathPtr
        );
    }

    /**
     * Write php session wire via libc (#21900 / #21922).
     *
     * Walks {@see __hashtable__} strKeys; string/int/bool/null values (php-src mod_php.c).
     */
    private static function emitSessionWireSaveToPath(Context $context, Value $sessionHt, Value $pathStr): void
    {
        LibcExtern::register($context);
        // Module-local open/close/write after LibcExtern always-on drop (#31817).
        LibcExtern::ensurePosixFd($context);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $strMap = $context->structFieldMap['__string__'];
        $valMap = $context->structFieldMap['__value__'];
        $nodePtrTy = $context->getTypeFromString('__strkey_node__*');
        $strPtrTy = $context->getTypeFromString('__string__*');

        $wireType = $i8->arrayType(4096);
        $wireBuf = $context->builder->alloca($wireType);
        $wirePtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($wireBuf, $i32->constInt(0, false), $i64->constInt(0, false)),
            $i8p
        );
        $wireLen = $context->builder->alloca($i64);
        $context->builder->store($i64->constInt(0, false), $wireLen);

        $nodeSlot = $context->builder->alloca($nodePtrTy);
        $head = $context->builder->load($context->builder->structGep($sessionHt, $htMap['strKeys']));
        $context->builder->store($head, $nodeSlot);

        $bbHead = BasicBlockHelper::append($context, 'ss_wire_head');
        $bbBody = BasicBlockHelper::append($context, 'ss_wire_body');
        $bbDone = BasicBlockHelper::append($context, 'ss_wire_done');
        $context->builder->branch($bbHead);

        $context->builder->positionAtEnd($bbHead);
        $node = $context->builder->load($nodeSlot);
        $isNullNode = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrTy->constNull());
        $context->builder->branchIf($isNullNode, $bbDone, $bbBody);

        $context->builder->positionAtEnd($bbBody);
        $keyStr = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $keyLen = $context->builder->load($context->builder->structGep($keyStr, $strMap['length']));
        $keyBytes = $context->builder->structGep($keyStr, $strMap['value']);
        $valEntry = $context->builder->structGep($node, $nodeMap['value']);
        $typeByte = $context->builder->load($context->builder->structGep($valEntry, $valMap['type']));
        $kind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));
        $bbStr = BasicBlockHelper::append($context, 'ss_wire_str');
        $bbLong = BasicBlockHelper::append($context, 'ss_wire_long');
        $bbBool = BasicBlockHelper::append($context, 'ss_wire_bool');
        $bbNull = BasicBlockHelper::append($context, 'ss_wire_null');
        $bbNext = BasicBlockHelper::append($context, 'ss_wire_next');
        $bbAfterStr = BasicBlockHelper::append($context, 'ss_wire_after_str');
        $bbAfterLong = BasicBlockHelper::append($context, 'ss_wire_after_long');
        $bbAfterBool = BasicBlockHelper::append($context, 'ss_wire_after_bool');
        $isString = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(4, false));
        $context->builder->branchIf($isString, $bbStr, $bbAfterStr);

        $context->builder->positionAtEnd($bbStr);
        $valField = $context->builder->structGep($valEntry, $valMap['value']);
        $valStrPtrPtr = $context->builder->pointerCast($valField, $strPtrTy->pointerType(0));
        $valStr = $context->builder->load($valStrPtrPtr);
        $valLen = $context->builder->load($context->builder->structGep($valStr, $strMap['length']));
        $valBytes = $context->builder->structGep($valStr, $strMap['value']);
        $cur = $context->builder->load($wireLen);
        $dst = $context->builder->inBoundsGEP($wirePtr, $cur);
        $fmtStr = $context->builder->pointerCast(
            $context->constantFromString('%.*s|s:%lld:"%.*s";'),
            $i8p
        );
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $wrote = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $dst,
            $sizeT->constInt(512, false),
            $fmtStr,
            $context->builder->trunc($keyLen, $i32),
            $keyBytes,
            $valLen,
            $context->builder->trunc($valLen, $i32),
            $valBytes
        );
        $context->builder->store(
            $context->builder->add($cur, $context->builder->sext($wrote, $i64)),
            $wireLen
        );
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbAfterStr);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(1, false)); // NATIVE_LONG
        $context->builder->branchIf($isLong, $bbLong, $bbAfterLong);

        $context->builder->positionAtEnd($bbLong);
        $longPtr = $context->builder->pointerCast(
            $context->builder->structGep($valEntry, $valMap['value']),
            $i64->pointerType(0)
        );
        $longVal = $context->builder->load($longPtr);
        $curL = $context->builder->load($wireLen);
        $dstL = $context->builder->inBoundsGEP($wirePtr, $curL);
        $fmtLong = $context->builder->pointerCast(
            $context->constantFromString('%.*s|i:%lld;'),
            $i8p
        );
        $wroteL = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $dstL,
            $sizeT->constInt(256, false),
            $fmtLong,
            $context->builder->trunc($keyLen, $i32),
            $keyBytes,
            $longVal
        );
        $context->builder->store(
            $context->builder->add($curL, $context->builder->sext($wroteL, $i64)),
            $wireLen
        );
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbAfterLong);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(2, false)); // NATIVE_BOOL
        $context->builder->branchIf($isBool, $bbBool, $bbAfterBool);

        $context->builder->positionAtEnd($bbBool);
        $boolByte = $context->builder->load(
            $context->builder->pointerCast(
                $context->builder->structGep($valEntry, $valMap['value']),
                $i8->pointerType(0)
            )
        );
        $boolOne = $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false));
        $boolDigit = $context->builder->select(
            $boolOne,
            $i32->constInt(1, false),
            $i32->constInt(0, false)
        );
        $curB = $context->builder->load($wireLen);
        $dstB = $context->builder->inBoundsGEP($wirePtr, $curB);
        $fmtBool = $context->builder->pointerCast(
            $context->constantFromString('%.*s|b:%d;'),
            $i8p
        );
        $wroteB = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $dstB,
            $sizeT->constInt(256, false),
            $fmtBool,
            $context->builder->trunc($keyLen, $i32),
            $keyBytes,
            $boolDigit
        );
        $context->builder->store(
            $context->builder->add($curB, $context->builder->sext($wroteB, $i64)),
            $wireLen
        );
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbAfterBool);
        $isNullVal = $context->builder->icmp(Builder::INT_EQ, $kind, $i8->constInt(0, false));
        $context->builder->branchIf($isNullVal, $bbNull, $bbNext);

        $context->builder->positionAtEnd($bbNull);
        $curN = $context->builder->load($wireLen);
        $dstN = $context->builder->inBoundsGEP($wirePtr, $curN);
        $fmtNull = $context->builder->pointerCast(
            $context->constantFromString('%.*s|N;'),
            $i8p
        );
        $wroteN = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $dstN,
            $sizeT->constInt(256, false),
            $fmtNull,
            $context->builder->trunc($keyLen, $i32),
            $keyBytes
        );
        $context->builder->store(
            $context->builder->add($curN, $context->builder->sext($wroteN, $i64)),
            $wireLen
        );
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $nodeSlot);
        $context->builder->branch($bbHead);

        $context->builder->positionAtEnd($bbDone);
        $finalLen = $context->builder->load($wireLen);
        $pathMap = $strMap;
        $pathLen = $context->builder->load($context->builder->structGep($pathStr, $pathMap['length']));
        $pathBytes = $context->builder->structGep($pathStr, $pathMap['value']);
        $pathC = $context->builder->alloca($i8->arrayType(640));
        $pathCPtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP($pathC, $i32->constInt(0, false), $i64->constInt(0, false)),
            $i8p
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $pathCPtr,
            $pathBytes,
            $context->builder->intCast($pathLen, $sizeT)
        );
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($pathCPtr, $pathLen)
        );
        $fd = $context->builder->call(
            $context->lookupFunction('open'),
            $pathCPtr,
            $i32->constInt(577, false),
            $i32->constInt(0600, false)
        );
        $okFd = $context->builder->icmp(Builder::INT_SGE, $fd, $i32->constInt(0, false));
        $bbWrite = BasicBlockHelper::append($context, 'ss_wire_write');
        $bbAfter = BasicBlockHelper::append($context, 'ss_wire_after');
        $context->builder->branchIf($okFd, $bbWrite, $bbAfter);

        $context->builder->positionAtEnd($bbWrite);
        $context->builder->call(
            $context->lookupFunction('write'),
            $fd,
            $wirePtr,
            $context->builder->intCast($finalLen, $sizeT)
        );
        $context->builder->call($context->lookupFunction('close'), $fd);
        $context->builder->branch($bbAfter);

        $context->builder->positionAtEnd($bbAfter);
    }

    /** mkdir(PHP_COMPILER_SESSION_DIR) via libc — ignore EEXIST (#21900). */
    private static function emitEnsureSessionDir(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        self::ensureLibcMkdir($context);
        StringGetenv::ensureLibcGetenv($context);
        $dirKey = $context->builder->pointerCast(
            $context->constantFromString('PHP_COMPILER_SESSION_DIR'),
            $i8p
        );
        $dirEnv = $context->builder->call($context->lookupFunction('getenv'), $dirKey);
        $hasDir = $context->builder->icmp(Builder::INT_NE, $dirEnv, $i8p->constNull());
        $bbMk = BasicBlockHelper::append($context, 'ss_mkdir_session_dir');
        $bbSkip = BasicBlockHelper::append($context, 'ss_mkdir_session_dir_skip');
        $context->builder->branchIf($hasDir, $bbMk, $bbSkip);

        $context->builder->positionAtEnd($bbMk);
        $nonEmpty = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($dirEnv),
            $i8->constInt(0, false)
        );
        $bbDo = BasicBlockHelper::append($context, 'ss_mkdir_session_dir_do');
        $context->builder->branchIf($nonEmpty, $bbDo, $bbSkip);

        $context->builder->positionAtEnd($bbDo);
        $context->builder->call(
            $context->lookupFunction('mkdir'),
            $dirEnv,
            $i32->constInt(0700, false)
        );
        $context->builder->branch($bbSkip);

        $context->builder->positionAtEnd($bbSkip);
    }

    /** Module-local mkdir(2) after LibcExtern/Module always-on drop (#31374). */
    private static function ensureLibcMkdir(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        self::ensureExternal(
            $context,
            'mkdir',
            $context->context->functionType($i32, false, $i8p, $i32)
        );
    }

    /**
     * Parse HTTP_COOKIE via libc (getenv/strncmp/strchr) — NestedJIT strpos/substr segfault (#21900).
     *
     * php-src: ext/session/session.c php_session_reset_id / cookie lookup
     */
    private static function implementApplyCookieBridge(Context $context, LlvmFunction $fn): void
    {
        LibcExtern::register($context);
        StringGetenv::ensureLibcGetenv($context);

        $entry = $fn->appendBasicBlock('ss_cookie_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $nullI8p = $i8p->constNull();

        $bbFail = BasicBlockHelper::append($context, 'ss_cookie_bridge_fail');
        $bbOk = BasicBlockHelper::append($context, 'ss_cookie_bridge_ok');
        $bbGetenv = BasicBlockHelper::append($context, 'ss_cookie_bridge_getenv');
        $bbLoopInit = BasicBlockHelper::append($context, 'ss_cookie_bridge_loop_init');
        $bbLoop = BasicBlockHelper::append($context, 'ss_cookie_bridge_loop');
        $bbSkipWs = BasicBlockHelper::append($context, 'ss_cookie_bridge_skip_ws');
        $bbAdvWs = BasicBlockHelper::append($context, 'ss_cookie_bridge_adv_ws');
        $bbAfterWs = BasicBlockHelper::append($context, 'ss_cookie_bridge_after_ws');
        $bbCheckEq = BasicBlockHelper::append($context, 'ss_cookie_bridge_check_eq');
        $bbCopy = BasicBlockHelper::append($context, 'ss_cookie_bridge_copy');
        $bbNextPair = BasicBlockHelper::append($context, 'ss_cookie_bridge_next_pair');
        $bbEndSemi = BasicBlockHelper::append($context, 'ss_cookie_bridge_end_semi');
        $bbEndNul = BasicBlockHelper::append($context, 'ss_cookie_bridge_end_nul');
        $bbSanitize = BasicBlockHelper::append($context, 'ss_cookie_bridge_sanitize');
        $bbSanLoop = BasicBlockHelper::append($context, 'ss_cookie_bridge_san_loop');
        $bbSanBody = BasicBlockHelper::append($context, 'ss_cookie_bridge_san_body');
        $bbSanStore = BasicBlockHelper::append($context, 'ss_cookie_bridge_san_store');
        $bbSanNext = BasicBlockHelper::append($context, 'ss_cookie_bridge_san_next');
        $bbSanDone = BasicBlockHelper::append($context, 'ss_cookie_bridge_san_done');

        $nameLen = $context->builder->load(SessionStorageGlobals::$nameLenGlobal);
        $hasName = $context->builder->icmp(Builder::INT_SGT, $nameLen, $zeroI64);
        $context->builder->branchIf($hasName, $bbGetenv, $bbFail);

        $context->builder->positionAtEnd($bbGetenv);
        $cookieKey = $context->builder->pointerCast(
            $context->constantFromString('HTTP_COOKIE'),
            $i8p
        );
        $header = $context->builder->call($context->lookupFunction('getenv'), $cookieKey);
        $hasHeader = $context->builder->icmp(Builder::INT_NE, $header, $nullI8p);
        $context->builder->branchIf($hasHeader, $bbLoopInit, $bbFail);

        $context->builder->positionAtEnd($bbLoopInit);
        $cursorSlot = $context->builder->alloca($i8p);
        $context->builder->store($header, $cursorSlot);
        $namePtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP(
                SessionStorageGlobals::$nameBufGlobal,
                $i32->constInt(0, false),
                $zeroI64
            ),
            $i8p
        );
        $nameLenSize = $context->builder->intCast($nameLen, $sizeT);
        $valueEndSlot = $context->builder->alloca($i8p);
        $outIdx = $context->builder->alloca($i64);
        $srcIdx = $context->builder->alloca($i64);
        $copyCapSlot = $context->builder->alloca($i64);
        $valueStartSlot = $context->builder->alloca($i8p);
        $chSlot = $context->builder->alloca($i8);
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbLoop);
        $context->builder->branch($bbSkipWs);

        $context->builder->positionAtEnd($bbSkipWs);
        $cursor = $context->builder->load($cursorSlot);
        $c = $context->builder->load($cursor);
        $isSpace = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(0x20, false));
        $isTab = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(0x09, false));
        $isWs = $context->builder->or($isSpace, $isTab);
        $context->builder->branchIf($isWs, $bbAdvWs, $bbAfterWs);

        $context->builder->positionAtEnd($bbAdvWs);
        $cursor = $context->builder->load($cursorSlot);
        $context->builder->store(
            $context->builder->inBoundsGEP($cursor, $oneI64),
            $cursorSlot
        );
        $context->builder->branch($bbSkipWs);

        $context->builder->positionAtEnd($bbAfterWs);
        $cursor = $context->builder->load($cursorSlot);
        $atEnd = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($cursor),
            $i8->constInt(0, false)
        );
        $bbTryMatch = BasicBlockHelper::append($context, 'ss_cookie_bridge_try_match');
        $context->builder->branchIf($atEnd, $bbFail, $bbTryMatch);

        $context->builder->positionAtEnd($bbTryMatch);
        $cmp = $context->builder->call(
            $context->lookupFunction('strncmp'),
            $cursor,
            $namePtr,
            $nameLenSize
        );
        $nameMatch = $context->builder->icmp(Builder::INT_EQ, $cmp, $zeroI32);
        $context->builder->branchIf($nameMatch, $bbCheckEq, $bbNextPair);

        $context->builder->positionAtEnd($bbCheckEq);
        $eqPtr = $context->builder->inBoundsGEP($cursor, $nameLen);
        $eqCh = $context->builder->load($eqPtr);
        $isEq = $context->builder->icmp(Builder::INT_EQ, $eqCh, $i8->constInt(0x3d, false));
        $context->builder->branchIf($isEq, $bbCopy, $bbNextPair);

        $context->builder->positionAtEnd($bbCopy);
        $valueStart = $context->builder->inBoundsGEP($eqPtr, $oneI64);
        $context->builder->store($valueStart, $valueStartSlot);
        $semi = $context->builder->call(
            $context->lookupFunction('strchr'),
            $valueStart,
            $i32->constInt(0x3b, false)
        );
        $hasSemi = $context->builder->icmp(Builder::INT_NE, $semi, $nullI8p);
        $context->builder->branchIf($hasSemi, $bbEndSemi, $bbEndNul);

        $context->builder->positionAtEnd($bbEndSemi);
        $context->builder->store($semi, $valueEndSlot);
        $context->builder->branch($bbSanitize);

        $context->builder->positionAtEnd($bbEndNul);
        // strlen(3) via LibcExtern::ensureStrlenDecl after always-on drop (#32068).
        LibcExtern::ensureStrlenDecl($context);
        $valueLenRaw = $context->builder->call(
            $context->lookupFunction('strlen'),
            $context->builder->load($valueStartSlot)
        );
        $endPtr = $context->builder->inBoundsGEP(
            $context->builder->load($valueStartSlot),
            $context->builder->intCast($valueLenRaw, $i64)
        );
        $context->builder->store($endPtr, $valueEndSlot);
        $context->builder->branch($bbSanitize);

        $context->builder->positionAtEnd($bbSanitize);
        $valueStart = $context->builder->load($valueStartSlot);
        $valueEnd = $context->builder->load($valueEndSlot);
        $rawLen = $context->builder->sub(
            $context->builder->ptrToInt($valueEnd, $i64),
            $context->builder->ptrToInt($valueStart, $i64)
        );
        $maxLen = $i64->constInt(VmSession::MAX_ID_LEN, false);
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $rawLen, $maxLen);
        $context->builder->store(
            $context->builder->select($tooLong, $maxLen, $rawLen),
            $copyCapSlot
        );
        $context->builder->store($zeroI64, $outIdx);
        $context->builder->store($zeroI64, $srcIdx);
        $context->builder->branch($bbSanLoop);

        $context->builder->positionAtEnd($bbSanLoop);
        $si = $context->builder->load($srcIdx);
        $copyCap = $context->builder->load($copyCapSlot);
        $moreSrc = $context->builder->icmp(Builder::INT_ULT, $si, $copyCap);
        $context->builder->branchIf($moreSrc, $bbSanBody, $bbSanDone);

        $context->builder->positionAtEnd($bbSanBody);
        $valueStart = $context->builder->load($valueStartSlot);
        $ch = $context->builder->load($context->builder->inBoundsGEP($valueStart, $si));
        $context->builder->store($ch, $chSlot);
        $ch32 = $context->builder->zExt($ch, $i32);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch32, $i32->constInt(0x30, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch32, $i32->constInt(0x39, false))
        );
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch32, $i32->constInt(0x41, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch32, $i32->constInt(0x5a, false))
        );
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch32, $i32->constInt(0x61, false)),
            $context->builder->icmp(Builder::INT_SLE, $ch32, $i32->constInt(0x7a, false))
        );
        $isComma = $context->builder->icmp(Builder::INT_EQ, $ch32, $i32->constInt(0x2c, false));
        $isHyphen = $context->builder->icmp(Builder::INT_EQ, $ch32, $i32->constInt(0x2d, false));
        $okChar = $context->builder->or(
            $context->builder->or($isDigit, $isUpper),
            $context->builder->or($isLower, $context->builder->or($isComma, $isHyphen))
        );
        $context->builder->branchIf($okChar, $bbSanStore, $bbSanNext);

        $context->builder->positionAtEnd($bbSanStore);
        $oi = $context->builder->load($outIdx);
        $context->builder->store(
            $context->builder->load($chSlot),
            $context->builder->inBoundsGEP(self::idBufPtr($context), $oi)
        );
        $context->builder->store($context->builder->add($oi, $oneI64), $outIdx);
        $context->builder->branch($bbSanNext);

        $context->builder->positionAtEnd($bbSanNext);
        $context->builder->store(
            $context->builder->add($context->builder->load($srcIdx), $oneI64),
            $srcIdx
        );
        $context->builder->branch($bbSanLoop);

        $context->builder->positionAtEnd($bbSanDone);
        $finalLen = $context->builder->load($outIdx);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP(self::idBufPtr($context), $finalLen)
        );
        $context->builder->store($finalLen, SessionStorageGlobals::$idLenGlobal);
        $hasId = $context->builder->icmp(Builder::INT_SGT, $finalLen, $zeroI64);
        $context->builder->branchIf($hasId, $bbOk, $bbFail);

        $context->builder->positionAtEnd($bbNextPair);
        $cursor = $context->builder->load($cursorSlot);
        $semiNext = $context->builder->call(
            $context->lookupFunction('strchr'),
            $cursor,
            $i32->constInt(0x3b, false)
        );
        $hasNext = $context->builder->icmp(Builder::INT_NE, $semiNext, $nullI8p);
        $bbAdvance = BasicBlockHelper::append($context, 'ss_cookie_bridge_advance');
        $context->builder->branchIf($hasNext, $bbAdvance, $bbFail);

        $context->builder->positionAtEnd($bbAdvance);
        $context->builder->store(
            $context->builder->inBoundsGEP($semiNext, $oneI64),
            $cursorSlot
        );
        $context->builder->branch($bbLoop);

        $context->builder->positionAtEnd($bbOk);
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
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $hasId = $context->builder->icmp(Builder::INT_SGT, $idLen, $i64->constInt(0, false));
        $bbDone = BasicBlockHelper::append($context, 'ss_setcookie_bridge_done');
        $bbWork = BasicBlockHelper::append($context, 'ss_setcookie_bridge_work');
        $context->builder->branchIf($hasId, $bbWork, $bbDone);

        $context->builder->positionAtEnd($bbWork);
        // Thin AOT keeps __phpc_setcookie_add as a no-op *_link_stub (#21900). Print
        // CGI Set-Cookie from session globals (not __string__init — buffer-backed
        // strings mis-feed %.*s). NestedJIT PendingHeaders upgrade still TODO.
        if (self::pendingSetcookieIsThinLinkStub($context)) {
            self::emitSetcookiePrintfFromGlobals($context, $idLen);
        } else {
            $nameStr = self::bufferToString(
                $context,
                SessionStorageGlobals::$nameBufGlobal,
                $context->builder->load(SessionStorageGlobals::$nameLenGlobal)
            );
            $valueStr = self::bufferToString($context, SessionStorageGlobals::$idBufGlobal, $idLen);
            $pathStr = self::literalString($context, '/');
            $emptyStr = self::literalString($context, '');
            $context->builder->call(
                $context->lookupFunction('__phpc_setcookie_add'),
                $nameStr,
                $valueStr,
                $i64->constInt(0, false),
                $pathStr,
                $emptyStr,
                $i32->constInt(0, false),
                $i32->constInt(0, false),
                $emptyStr,
                $i32->constInt(0, false)
            );
        }
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    /**
     * True when thin AOT filled {@see __phpc_setcookie_add} with a no-op *_link_stub (#21900).
     */
    private static function pendingSetcookieIsThinLinkStub(Context $context): bool
    {
        $fn = $context->module->getNamedFunction('__phpc_setcookie_add');
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            return true;
        }
        try {
            foreach ($fn->getBasicBlocks() as $block) {
                if (str_contains($block->getName(), '_link_stub')) {
                    return true;
                }
            }
        } catch (\Throwable) {
        }

        return false;
    }

    /** Direct printf from session name/id byte buffers (#21900). */
    private static function emitSetcookiePrintfFromGlobals(Context $context, Value $idLen): void
    {
        // Module-local printf(3) after LibcExtern always-on drop (#31706).
        LibcExtern::ensurePrintf($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $nameLen = $context->builder->load(SessionStorageGlobals::$nameLenGlobal);
        $namePtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP(
                SessionStorageGlobals::$nameBufGlobal,
                $i32->constInt(0, false),
                $i64->constInt(0, false)
            ),
            $i8p
        );
        $idPtr = $context->builder->pointerCast(
            $context->builder->inBoundsGEP(
                SessionStorageGlobals::$idBufGlobal,
                $i32->constInt(0, false),
                $i64->constInt(0, false)
            ),
            $i8p
        );
        $fmt = $context->builder->pointerCast(
            $context->constantFromString("Set-Cookie: %.*s=%.*s; path=/\r\n"),
            $context->getTypeFromString('char*')
        );
        $context->builder->call(
            $context->lookupFunction('printf'),
            $fmt,
            $nameLen,
            $namePtr,
            $idLen,
            $idPtr
        );
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

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23284');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23284'
        );
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after JitSessionStorageKernel bridge (#9495)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
