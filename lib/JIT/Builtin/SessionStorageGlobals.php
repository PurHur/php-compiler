<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM globals for JIT/AOT session_id() / session_name() buffers (issues #1183–#1184, #5694, #5750).
 *
 * VM source of truth: {@see VmSession}. MCJIT defines these globals in LLVM; standalone AOT
 * links storage from {@see lib/JIT/Builtin/SessionStorageGlobals} LLVM globals (merged #5750).
 * php-src: ext/session/session.c (PS(id), PS(session_name))
 */
final class SessionStorageGlobals
{
    public const GLOBAL_ID_BUF = '__phpc_session_id_storage';

    public const GLOBAL_ID_LEN = '__phpc_session_id_len';

    public const GLOBAL_NAME_BUF = '__phpc_session_name_storage';

    public const GLOBAL_NAME_LEN = '__phpc_session_name_len';

    public const GLOBAL_ACTIVE = '__phpc_session_active';

    public const GLOBAL_MODULE_BUF = '__phpc_session_module_storage';

    public const GLOBAL_MODULE_LEN = '__phpc_session_module_len';

    public const GLOBAL_CACHE_EXPIRE = '__phpc_session_cache_expire';

    /** @var Value|null */
    public static $idBufGlobal = null;

    /** @var Value|null */
    public static $idLenGlobal = null;

    /** @var Value|null */
    public static $nameBufGlobal = null;

    /** @var Value|null */
    public static $nameLenGlobal = null;

    /** @var Value|null */
    public static $activeGlobal = null;

    /** @var Value|null */
    public static $moduleBufGlobal = null;

    /** @var Value|null */
    public static $moduleLenGlobal = null;

    /** @var Value|null */
    public static $cacheExpireGlobal = null;

    public static function ensureGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $idBufType = $i8->arrayType(VmSession::MAX_ID_LEN + 1);
        $nameBufType = $i8->arrayType(VmSession::MAX_NAME_LEN + 1);
        $defaultNameLen = \strlen(VmSession::DEFAULT_NAME);
        $moduleBufType = $i8->arrayType(VmSession::MAX_MODULE_LEN + 1);
        $defaultModuleLen = \strlen(VmSession::DEFAULT_MODULE);

        self::$idBufGlobal = self::ensureGlobal($context, $idBufType, self::GLOBAL_ID_BUF, $idBufType->constNull());
        self::$idLenGlobal = self::ensureGlobal($context, $i64, self::GLOBAL_ID_LEN, $i64->constInt(0, false));
        self::$nameBufGlobal = self::ensureGlobal($context, $nameBufType, self::GLOBAL_NAME_BUF, $nameBufType->constNull());
        self::$nameLenGlobal = self::ensureGlobal(
            $context,
            $i64,
            self::GLOBAL_NAME_LEN,
            $i64->constInt($defaultNameLen, false)
        );
        self::$activeGlobal = self::ensureGlobal($context, $i8, self::GLOBAL_ACTIVE, $i8->constInt(0, false));
        self::$moduleBufGlobal = self::ensureGlobal(
            $context,
            $moduleBufType,
            self::GLOBAL_MODULE_BUF,
            $moduleBufType->constNull()
        );
        self::$moduleLenGlobal = self::ensureGlobal(
            $context,
            $i64,
            self::GLOBAL_MODULE_LEN,
            $i64->constInt($defaultModuleLen, false)
        );
        self::$cacheExpireGlobal = self::ensureGlobal(
            $context,
            $i64,
            self::GLOBAL_CACHE_EXPIRE,
            $i64->constInt(VmSession::DEFAULT_CACHE_EXPIRE, false)
        );
    }

    /** @param mixed $initializer */
    private static function ensureGlobal(Context $context, $llvmType, string $name, $initializer): Value
    {
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            $global = $context->module->addGlobal($llvmType, $name);
        }
        $global->setInitializer($initializer);

        return $global;
    }

    /**
     * Idempotent seed of default session name (replaces phpc_session_state.c buffer init, #5750).
     */
    public static function implementEnsureDefaults(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_session_ensure_defaults');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__phpc_session_ensure_defaults', $probe);

            return;
        }

        if (null === self::$nameLenGlobal || null === self::$moduleLenGlobal) {
            self::ensureGlobals($context);
        }

        $void = $context->context->voidType();
        $fn = $context->module->addFunction(
            '__phpc_session_ensure_defaults',
            $context->context->functionType($void, false)
        );
        $context->registerFunction('__phpc_session_ensure_defaults', $fn);

        $entry = $fn->appendBasicBlock('sess_defaults_entry');
        $bbSeed = $fn->appendBasicBlock('sess_defaults_seed');
        $bbDone = $fn->appendBasicBlock('sess_defaults_done');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $defaultLen = $i64->constInt(\strlen(VmSession::DEFAULT_NAME), false);
        $defaultModuleLen = $i64->constInt(\strlen(VmSession::DEFAULT_MODULE), false);

        $curLen = $context->builder->load(self::$nameLenGlobal);
        $nameBufPtr = $context->builder->inBoundsGEP(
            self::$nameBufGlobal,
            $i32->constInt(0, false),
            $zero
        );
        $firstByte = $context->builder->load($nameBufPtr);
        // name_len is initialized to strlen(PHPSESSID) but the buffer starts zeroed (#21900).
        $lenIsZero = $context->builder->icmp(\PHPLLVM\Builder::INT_EQ, $curLen, $zero);
        $bufIsEmpty = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $firstByte,
            $i8->constInt(0, false)
        );
        $needsSeed = $context->builder->or($lenIsZero, $bufIsEmpty);
        $context->builder->branchIf($needsSeed, $bbSeed, $bbDone);

        $context->builder->positionAtEnd($bbSeed);
        $context->builder->store($defaultLen, self::$nameLenGlobal);
        $bufPtr = $context->builder->inBoundsGEP(
            self::$nameBufGlobal,
            $i32->constInt(0, false),
            $zero
        );
        foreach (str_split(VmSession::DEFAULT_NAME) as $i => $ch) {
            $charPtr = $context->builder->inBoundsGEP($bufPtr, $i64->constInt($i, false));
            $context->builder->store($i8->constInt(\ord($ch), false), $charPtr);
        }
        $nulPtr = $context->builder->inBoundsGEP($bufPtr, $defaultLen);
        $context->builder->store($i8->constInt(0, false), $nulPtr);
        $modBufPtr = $context->builder->inBoundsGEP(
            self::$moduleBufGlobal,
            $i32->constInt(0, false),
            $zero
        );
        $context->builder->store($defaultModuleLen, self::$moduleLenGlobal);
        foreach (str_split(VmSession::DEFAULT_MODULE) as $i => $ch) {
            $charPtr = $context->builder->inBoundsGEP($modBufPtr, $i64->constInt($i, false));
            $context->builder->store($i8->constInt(\ord($ch), false), $charPtr);
        }
        $modNulPtr = $context->builder->inBoundsGEP($modBufPtr, $defaultModuleLen);
        $context->builder->store($i8->constInt(0, false), $modNulPtr);
        $context->builder->branch($bbDone);

        $context->builder->positionAtEnd($bbDone);
        $context->builder->returnVoid();
        $context->builder->clearInsertionPosition();
    }

    public static function emitCallEnsureDefaults(Context $context): void
    {
        self::ensureGlobals($context);
        self::implementEnsureDefaults($context);
        $context->builder->call($context->lookupFunction('__phpc_session_ensure_defaults'));
    }
}
