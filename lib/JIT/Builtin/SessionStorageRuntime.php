<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\session\SessionFileStorage;
use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM session file I/O for JIT/AOT (issue #6968, #5332 phase 1).
 *
 * Replaces lib/AOT/runtime/phpc_session_storage.c. php-src: ext/session/mod_files.c
 */
final class SessionStorageRuntime
{
    private const PATH_CAP = 512;

    private const ENCODE_CAP = 256 * 1024;

    private const G_SG_SESSION = 'sg_SESSION';

    private const G_SG_COOKIE = 'sg_COOKIE';

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        'phpc_session_load_from_disk',
        'phpc_session_save_to_disk',
        'phpc_session_unlink_file',
        'phpc_session_apply_incoming_cookie',
        'phpc_session_emit_setcookie',
    ];

    private static int $blockSuffix = 0;

    public static function ensureLinked(Context $context): void
    {
        SessionStorageGlobals::ensureGlobals($context);
        PendingHeadersRuntime::ensureLinked($context);
        StringUnserialize::ensureLinked($context);
        StringSerialize::implement($context);
        StringFileGetContents::implement($context);
        StringFilePutContents::implement($context);
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('phpc_session_load_from_disk');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        self::$blockSuffix = 0;
        self::ensureExternGlobals($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);
        self::ensureValueHelpers($context);

        self::implementIfMissing($context, 'phpc_session_merge_hashtable', self::emitMergeHashtable(...));
        self::implementIfMissing($context, 'phpc_session_load_from_disk', self::emitLoadFromDisk(...));
        self::implementIfMissing($context, 'phpc_session_save_to_disk', self::emitSaveToDisk(...));
        self::implementIfMissing($context, 'phpc_session_unlink_file', self::emitUnlinkFile(...));
        self::implementIfMissing($context, 'phpc_session_apply_incoming_cookie', self::emitApplyIncomingCookie(...));
        self::implementIfMissing($context, 'phpc_session_emit_setcookie', self::emitEmitSetcookie(...));

        self::registerLinkedRuntime($context);
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

    private static function emitLoadFromDisk(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_load_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $zeroI64 = $i64->constInt(0, false);
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $hasId = $context->builder->icmp(Builder::INT_SGT, $idLen, $zeroI64);
        $bbDone = BasicBlockHelper::append($context, 'ss_load_done');
        $bbWork = BasicBlockHelper::append($context, 'ss_load_work');
        $context->builder->branchIf($hasId, $bbWork, $bbDone);

        $context->builder->positionAtEnd($bbWork);
        $pathStr = self::buildStoragePathString($context);
        $content = $context->builder->call(
            $context->lookupFunction('__compiler_file_get_contents'),
            $pathStr
        );
        $strPtr = $context->getTypeFromString('__string__*');
        $hasContent = $context->builder->icmp(
            Builder::INT_NE,
            $content,
            $strPtr->constNull()
        );
        $bbDecode = BasicBlockHelper::append($context, 'ss_load_decode');
        $context->builder->branchIf($hasContent, $bbDecode, $bbDone);

        $context->builder->positionAtEnd($bbDecode);
        $strMap = $context->structFieldMap['__string__'];
        $bodyLen = $context->builder->load($context->builder->structGep($content, $strMap['length']));
        $bodyPtr = $context->builder->pointerCast(
            $context->builder->structGep($content, $strMap['value']),
            $context->getTypeFromString('int8*')
        );
        $bodySizeT = $context->builder->truncOrBitCast($bodyLen, $context->getTypeFromString('size_t'));
        $decoded = $context->builder->call(
            $context->lookupFunction('phpc_session_decode_payload'),
            $bodyPtr,
            $bodySizeT
        );
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $hasDecoded = $context->builder->icmp(Builder::INT_NE, $decoded, $htPtr->constNull());
        $bbMerge = BasicBlockHelper::append($context, 'ss_load_merge');
        $context->builder->branchIf($hasDecoded, $bbMerge, $bbDone);

        $context->builder->positionAtEnd($bbMerge);
        $sgSession = self::sgSessionPtr($context);
        $dest = $context->builder->load($sgSession);
        $destNull = $context->builder->icmp(Builder::INT_EQ, $dest, $htPtr->constNull());
        $bbAlloc = BasicBlockHelper::append($context, 'ss_load_alloc');
        $bbCopy = BasicBlockHelper::append($context, 'ss_load_copy');
        $context->builder->branchIf($destNull, $bbAlloc, $bbCopy);

        $context->builder->positionAtEnd($bbAlloc);
        $fresh = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($fresh, $sgSession);
        $context->builder->branch($bbCopy);

        $context->builder->positionAtEnd($bbCopy);
        $destHt = $context->builder->load($sgSession);
        $context->builder->call(
            $context->lookupFunction('phpc_session_merge_hashtable'),
            $destHt,
            $decoded
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function emitSaveToDisk(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_save_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zeroI64 = $i64->constInt(0, false);
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $session = $context->builder->load(self::sgSessionPtr($context));
        $canSave = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $idLen, $zeroI64),
            $context->builder->icmp(Builder::INT_NE, $session, $htPtr->constNull())
        );
        $bbDone = BasicBlockHelper::append($context, 'ss_save_done');
        $bbWork = BasicBlockHelper::append($context, 'ss_save_work');
        $context->builder->branchIf($canSave, $bbWork, $bbDone);

        $context->builder->positionAtEnd($bbWork);
        $payload = $context->builder->call(
            $context->lookupFunction('__compiler_serialize_hashtable'),
            $session
        );
        self::ensureStorageDir($context);
        $pathStr = self::buildStoragePathString($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_file_put_contents'),
            $pathStr,
            $payload,
            $i64->constInt(0, false)
        );
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function emitUnlinkFile(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_unlink_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $hasId = $context->builder->icmp(Builder::INT_SGT, $idLen, $i64->constInt(0, false));
        $bbDone = BasicBlockHelper::append($context, 'ss_unlink_done');
        $bbWork = BasicBlockHelper::append($context, 'ss_unlink_work');
        $context->builder->branchIf($hasId, $bbWork, $bbDone);

        $context->builder->positionAtEnd($bbWork);
        $pathCstr = self::buildStoragePathCstr($context);
        $context->builder->call($context->lookupFunction('remove'), $pathCstr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
    }

    private static function emitApplyIncomingCookie(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_cookie_entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $nameLen = $context->builder->load(SessionStorageGlobals::$nameLenGlobal);
        $hasName = $context->builder->icmp(Builder::INT_SGT, $nameLen, $i64->constInt(0, false));
        $bbFail = BasicBlockHelper::append($context, 'ss_cookie_fail');
        $bbLookup = BasicBlockHelper::append($context, 'ss_cookie_lookup');
        $context->builder->branchIf($hasName, $bbLookup, $bbFail);

        $context->builder->positionAtEnd($bbLookup);
        $cookies = $context->builder->load(self::sgCookiePtr($context));
        $hasCookies = $context->builder->icmp(Builder::INT_NE, $cookies, $htPtr->constNull());
        $bbRead = BasicBlockHelper::append($context, 'ss_cookie_read');
        $context->builder->branchIf($hasCookies, $bbRead, $bbFail);

        $context->builder->positionAtEnd($bbRead);
        $nameStr = self::bufferToString($context, SessionStorageGlobals::$nameBufGlobal, $nameLen);
        $valueBox = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyValue'),
            $cookies,
            $nameStr
        );
        $valuePtr = $context->getTypeFromString('__value__*');
        $hasValue = $context->builder->icmp(Builder::INT_NE, $valueBox, $valuePtr->constNull());
        $bbStore = BasicBlockHelper::append($context, 'ss_cookie_store');
        $context->builder->branchIf($hasValue, $bbStore, $bbFail);

        $context->builder->positionAtEnd($bbStore);
        $idStr = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $valueBox
        );
        self::storeSanitizedIdFromString($context, $idStr);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($bbFail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitEmitSetcookie(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_setcookie_entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $strPtr = $context->getTypeFromString('__string__*');
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);
        $hasId = $context->builder->icmp(Builder::INT_SGT, $idLen, $i64->constInt(0, false));
        $bbDone = BasicBlockHelper::append($context, 'ss_setcookie_done');
        $bbWork = BasicBlockHelper::append($context, 'ss_setcookie_work');
        $context->builder->branchIf($hasId, $bbWork, $bbDone);

        $context->builder->positionAtEnd($bbWork);
        $nameStr = self::bufferToString(
            $context,
            SessionStorageGlobals::$nameBufGlobal,
            $context->builder->load(SessionStorageGlobals::$nameLenGlobal)
        );
        $valueStr = self::bufferToString(
            $context,
            SessionStorageGlobals::$idBufGlobal,
            $idLen
        );
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

    private static function emitMergeHashtable(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('ss_merge_entry');
        $context->builder->positionAtEnd($entry);

        $dest = $fn->getParam(0);
        $src = $fn->getParam(1);
        $htMap = $context->structFieldMap['__hashtable__'];
        $nodeMap = $context->structFieldMap['__strkey_node__'];
        $valMap = $context->structFieldMap['__value__'];
        $nodePtrType = $context->getTypeFromString('__strkey_node__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $walkSlot = $context->builder->alloca($nodePtrType, 1, 'ss_merge_walk');
        $context->builder->store(
            $context->builder->load($context->builder->structGep($src, $htMap['strKeys'])),
            $walkSlot
        );

        $loopHead = BasicBlockHelper::append($context, 'ss_merge_head');
        $loopBody = BasicBlockHelper::append($context, 'ss_merge_body');
        $loopDone = BasicBlockHelper::append($context, 'ss_merge_done');
        $bbNull = BasicBlockHelper::append($context, 'ss_merge_null');
        $bbBool = BasicBlockHelper::append($context, 'ss_merge_bool');
        $bbLong = BasicBlockHelper::append($context, 'ss_merge_long');
        $bbString = BasicBlockHelper::append($context, 'ss_merge_string');
        $bbSkip = BasicBlockHelper::append($context, 'ss_merge_skip');
        $bbNext = BasicBlockHelper::append($context, 'ss_merge_next');
        $bbCheckBool = BasicBlockHelper::append($context, 'ss_merge_chk_bool');
        $bbCheckLong = BasicBlockHelper::append($context, 'ss_merge_chk_long');
        $bbCheckString = BasicBlockHelper::append($context, 'ss_merge_chk_str');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $node = $context->builder->load($walkSlot);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $node, $nodePtrType->constNull());
        $context->builder->branchIf($atEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $key = $context->builder->load($context->builder->structGep($node, $nodeMap['key']));
        $valField = $context->builder->structGep($node, $nodeMap['value']);
        $type = $context->builder->load($context->builder->structGep($valField, $valMap['type']));
        $typeMasked = $context->builder->and($type, $i8->constInt(0x7f, false));
        $isNull = $context->builder->icmp(Builder::INT_EQ, $typeMasked, $i8->constInt(0, false));
        $context->builder->branchIf($isNull, $bbNull, $bbCheckBool);

        $context->builder->positionAtEnd($bbCheckBool);
        $isBool = $context->builder->icmp(Builder::INT_EQ, $typeMasked, $i8->constInt(2, false));
        $context->builder->branchIf($isBool, $bbBool, $bbCheckLong);

        $context->builder->positionAtEnd($bbCheckLong);
        $isLong = $context->builder->icmp(Builder::INT_EQ, $typeMasked, $i8->constInt(1, false));
        $context->builder->branchIf($isLong, $bbLong, $bbCheckString);

        $context->builder->positionAtEnd($bbCheckString);
        $isString = $context->builder->icmp(Builder::INT_EQ, $typeMasked, $i8->constInt(4, false));
        $context->builder->branchIf($isString, $bbString, $bbSkip);

        $context->builder->positionAtEnd($bbNull);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->getTypeFromString('int8*')->constNull()
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $dest,
            $key,
            $empty
        );
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbBool);
        $i8 = $context->getTypeFromString('int8');
        $boolPtr = $context->builder->inBoundsGEP(
            $context->builder->structGep($valField, $valMap['value']),
            $i64->constInt(0, false),
            $i64->constInt(0, false)
        );
        $boolByte = $context->builder->load($boolPtr);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $dest,
            $key,
            $context->builder->icmp(Builder::INT_NE, $boolByte, $i8->constInt(0, false))
        );
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbLong);
        $longVal = $context->builder->load(
            $context->builder->bitcast(
                $context->builder->structGep($valField, $valMap['value']),
                $i64->pointerType(0)
            )
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $dest,
            $key,
            $longVal
        );
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbString);
        $strVal = $context->builder->load(
            $context->builder->bitcast(
                $context->builder->structGep($valField, $valMap['value']),
                $context->getTypeFromString('__string__*')->pointerType(0)
            )
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $dest,
            $key,
            $strVal
        );
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbSkip);
        $context->builder->branch($bbNext);

        $context->builder->positionAtEnd($bbNext);
        $next = $context->builder->load($context->builder->structGep($node, $nodeMap['next']));
        $context->builder->store($next, $walkSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnVoid();
    }

    private static function buildStoragePathString(Context $context): Value
    {
        $pathCstr = self::buildStoragePathCstr($context);
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $pathCstr
        );
        $lenI64 = $context->builder->zExt($len, $context->getTypeFromString('int64'));

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $pathCstr
        );
    }

    private static function buildStoragePathCstr(Context $context): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');

        $pathBuf = $context->builder->alloca($i8, self::PATH_CAP, 'ss_path');
        $pathPtr = $context->builder->pointerCast($pathBuf, $i8p);
        $dirCstr = self::storageDirCstr($context);
        $idLen = $context->builder->load(SessionStorageGlobals::$idLenGlobal);

        $idBufPtr = $context->builder->inBoundsGEP(
            SessionStorageGlobals::$idBufGlobal,
            $i32->constInt(0, false),
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $idCstr = $context->builder->pointerCast($idBufPtr, $i8p);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($idCstr, $idLen)
        );
        $fmt = self::literalCstr($context, '%s/'.SessionFileStorage::PATH_PREFIX.'%s');

        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $pathPtr,
            $sizeT->constInt(self::PATH_CAP, false),
            $fmt,
            $dirCstr,
            $idCstr
        );

        return $pathPtr;
    }

    private static function storageDirCstr(Context $context): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $envKey = self::literalCstr($context, 'PHP_COMPILER_SESSION_DIR');
        $fromEnv = $context->builder->call($context->lookupFunction('getenv'), $envKey);
        $nullPtr = $i8p->constNull();
        $hasEnv = $context->builder->icmp(Builder::INT_NE, $fromEnv, $nullPtr);
        $envNonEmpty = $context->builder->and(
            $hasEnv,
            $context->builder->icmp(
                Builder::INT_NE,
                $context->builder->load($fromEnv),
                $i8->constInt(0, false)
            )
        );
        $bbUseEnv = BasicBlockHelper::append($context, 'ss_dir_env_'.self::$blockSuffix++);
        $bbDefault = BasicBlockHelper::append($context, 'ss_dir_default_'.self::$blockSuffix++);
        $bbDone = BasicBlockHelper::append($context, 'ss_dir_done_'.self::$blockSuffix++);
        $dirSlot = $context->builder->alloca($i8p, 1, 'ss_dir');
        $context->builder->branchIf($envNonEmpty, $bbUseEnv, $bbDefault);

        $context->builder->positionAtEnd($bbUseEnv);
        $context->builder->store($fromEnv, $dirSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDefault);
        $context->builder->store(self::literalCstr($context, '/tmp/phpc_sessions'), $dirSlot);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);

        return $context->builder->load($dirSlot);
    }

    private static function ensureStorageDir(Context $context): void
    {
        $dirCstr = self::storageDirCstr($context);
        $context->builder->call(
            $context->lookupFunction('mkdir'),
            $dirCstr,
            $context->getTypeFromString('int32')->constInt(0700, false)
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

    private static function storeSanitizedIdFromString(Context $context, Value $idStr): void
    {
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $rawLen = $context->builder->load($context->builder->structGep($idStr, $strMap['length']));
        $rawPtr = $context->builder->pointerCast(
            $context->builder->structGep($idStr, $strMap['value']),
            $i8p
        );

        $outBuf = $context->builder->alloca(
            $i8,
            VmSession::MAX_ID_LEN + 1,
            'ss_id_out'
        );
        $outPtr = $context->builder->pointerCast($outBuf, $i8p);
        $outLenSlot = $context->builder->alloca($sizeT, 1, 'ss_id_out_len');
        $context->builder->store($sizeT->constInt(0, false), $outLenSlot);

        $idxSlot = $context->builder->alloca($i64, 1, 'ss_id_i');
        $context->builder->store($i64->constInt(0, false), $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'ss_id_head');
        $loopBody = BasicBlockHelper::append($context, 'ss_id_body');
        $loopStore = BasicBlockHelper::append($context, 'ss_id_store');
        $loopInc = BasicBlockHelper::append($context, 'ss_id_inc');
        $loopDone = BasicBlockHelper::append($context, 'ss_id_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($idxSlot);
        $done = $context->builder->icmp(Builder::INT_SGE, $i, $rawLen);
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($rawPtr, $i));
        $chI32 = $context->builder->zExt($ch, $i32);
        $isAlnum = $context->builder->or(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt(ord('a'), false)),
                $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt(ord('z'), false))
            ),
            $context->builder->or(
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt(ord('A'), false)),
                    $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt(ord('Z'), false))
                ),
                $context->builder->or(
                    $context->builder->and(
                        $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt(ord('0'), false)),
                        $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt(ord('9'), false))
                    ),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $chI32, $i32->constInt(ord(','), false)),
                        $context->builder->icmp(Builder::INT_EQ, $chI32, $i32->constInt(ord('-'), false))
                    )
                )
            )
        );
        $outLen = $context->builder->load($outLenSlot);
        $canStore = $context->builder->and(
            $isAlnum,
            $context->builder->icmp(Builder::INT_ULT, $outLen, $sizeT->constInt(VmSession::MAX_ID_LEN, false))
        );
        $context->builder->branchIf($canStore, $loopStore, $loopInc);

        $context->builder->positionAtEnd($loopStore);
        $outAt = $context->builder->inBoundsGEP($outPtr, $outLen);
        $context->builder->store($ch, $outAt);
        $context->builder->store(
            $context->builder->add($outLen, $sizeT->constInt(1, false)),
            $outLenSlot
        );
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->add($i, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $finalLen = $context->builder->load($outLenSlot);
        $finalLenI64 = $context->builder->zExt($finalLen, $i64);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($outPtr, $finalLen)
        );
        $hasId = $context->builder->icmp(Builder::INT_UGT, $finalLen, $sizeT->constInt(0, false));
        $bbWrite = BasicBlockHelper::append($context, 'ss_id_write');
        $bbSkip = BasicBlockHelper::append($context, 'ss_id_skip');
        $context->builder->branchIf($hasId, $bbWrite, $bbSkip);

        $context->builder->positionAtEnd($bbWrite);
        $context->builder->store($finalLenI64, SessionStorageGlobals::$idLenGlobal);
        $idDest = $context->builder->inBoundsGEP(
            SessionStorageGlobals::$idBufGlobal,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
        $idDestPtr = $context->builder->pointerCast($idDest, $i8p);
        $context->intrinsic->memcpy($idDestPtr, $outPtr, $finalLen, false);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($idDestPtr, $finalLenI64)
        );
        $context->builder->branch($bbSkip);

        $context->builder->positionAtEnd($bbSkip);
    }

    /** Sanitize session id/prefix to [a-zA-Z0-9,-] (php-src PS_MOD_FILES; #6002). */
    public static function sanitizeIdString(Context $context, Value $idStr): Value
    {
        $strMap = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $rawLen = $context->builder->load($context->builder->structGep($idStr, $strMap['length']));
        $rawPtr = $context->builder->pointerCast(
            $context->builder->structGep($idStr, $strMap['value']),
            $i8p
        );

        $outBuf = $context->builder->alloca(
            $i8,
            VmSession::MAX_ID_LEN + 1,
            'ss_sanitize_out'
        );
        $outPtr = $context->builder->pointerCast($outBuf, $i8p);
        $outLenSlot = $context->builder->alloca($sizeT, 1, 'ss_sanitize_out_len');
        $context->builder->store($sizeT->constInt(0, false), $outLenSlot);

        $idxSlot = $context->builder->alloca($i64, 1, 'ss_sanitize_i');
        $context->builder->store($i64->constInt(0, false), $idxSlot);

        $loopHead = BasicBlockHelper::append($context, 'ss_sanitize_head');
        $loopBody = BasicBlockHelper::append($context, 'ss_sanitize_body');
        $loopStore = BasicBlockHelper::append($context, 'ss_sanitize_store');
        $loopInc = BasicBlockHelper::append($context, 'ss_sanitize_inc');
        $loopDone = BasicBlockHelper::append($context, 'ss_sanitize_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($idxSlot);
        $done = $context->builder->icmp(Builder::INT_SGE, $i, $rawLen);
        $context->builder->branchIf($done, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($rawPtr, $i));
        $chI32 = $context->builder->zExt($ch, $i32);
        $isAlnum = $context->builder->or(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt(ord('a'), false)),
                $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt(ord('z'), false))
            ),
            $context->builder->or(
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt(ord('A'), false)),
                    $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt(ord('Z'), false))
                ),
                $context->builder->or(
                    $context->builder->and(
                        $context->builder->icmp(Builder::INT_SGE, $chI32, $i32->constInt(ord('0'), false)),
                        $context->builder->icmp(Builder::INT_SLE, $chI32, $i32->constInt(ord('9'), false))
                    ),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $chI32, $i32->constInt(ord(','), false)),
                        $context->builder->icmp(Builder::INT_EQ, $chI32, $i32->constInt(ord('-'), false))
                    )
                )
            )
        );
        $outLen = $context->builder->load($outLenSlot);
        $canStore = $context->builder->and(
            $isAlnum,
            $context->builder->icmp(Builder::INT_ULT, $outLen, $sizeT->constInt(VmSession::MAX_ID_LEN, false))
        );
        $context->builder->branchIf($canStore, $loopStore, $loopInc);

        $context->builder->positionAtEnd($loopStore);
        $outAt = $context->builder->inBoundsGEP($outPtr, $outLen);
        $context->builder->store($ch, $outAt);
        $context->builder->store(
            $context->builder->add($outLen, $sizeT->constInt(1, false)),
            $outLenSlot
        );
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $context->builder->store(
            $context->builder->add($i, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $finalLen = $context->builder->load($outLenSlot);
        $finalLenI64 = $context->builder->zExt($finalLen, $i64);
        $context->builder->store(
            $i8->constInt(0, false),
            $context->builder->inBoundsGEP($outPtr, $finalLen)
        );

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $finalLenI64,
            $outPtr
        );
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
        }

        return $context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        return $context->builder->pointerCast(
            $context->constantFromString($text),
            $context->getTypeFromString('char*')
        );
    }

    private static function literalString(Context $context, string $text): Value
    {
        $litGlobal = $context->constantStringFromString($text);
        $litPtr = $context->builder->load($litGlobal);

        return $litPtr;
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
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $void = $context->getTypeFromString('void');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');

        self::ensureExternal($context, 'getenv', $context->context->functionType($i8p, false, $i8p));
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
        self::ensureExternal(
            $context,
            'snprintf',
            $context->context->functionType($i32, true, $charPtr, $sizeT, $charPtr)
        );
        self::ensureExternal($context, 'mkdir', $context->context->functionType($i32, false, $i8p, $i32));
        self::ensureExternal($context, 'remove', $context->context->functionType($i32, false, $i8p));
        self::ensureExternal($context, 'free', $context->context->functionType($void, false, $i8p));
        self::ensureExternal($context, 'strncmp', $context->context->functionType($i32, false, $i8p, $i8p, $sizeT));
        self::ensureExternal($context, 'stat', $context->context->functionType($i32, false, $i8p, $i8p));
        self::ensureExternal($context, 'time', $context->context->functionType($i64, false, $i8p));
        self::ensureExternal(
            $context,
            'scandir',
            $context->context->functionType($i32, false, $i8p, $i8pp, $i8p, $i8p)
        );
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringKeyString', $void, [$htPtr, $strPtr, $strPtr]],
                ['__hashtable__setStringKeyLong', $void, [$htPtr, $strPtr, $i64]],
                ['__hashtable__setStringKeyBool', $void, [$htPtr, $strPtr, $context->getTypeFromString('int1')]],
                ['__hashtable__readStringKeyValue', $valuePtr, [$htPtr, $strPtr]],
                ['__string__init', $strPtr, [$i64, $i8p]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureValueHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        self::ensureExternal(
            $context,
            '__value__readString',
            $context->context->functionType($strPtr, false, $valuePtr)
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn || 0 === $fn->countBasicBlocks()) {
                throw new \LogicException($name.' missing after SessionStorageRuntime LLVM implement');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
