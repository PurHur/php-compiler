<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT\Builtin;
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

    public static function ensureGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $idBufType = $i8->arrayType(VmSession::MAX_ID_LEN + 1);
        $nameBufType = $i8->arrayType(VmSession::MAX_NAME_LEN + 1);
        $defaultNameLen = \strlen(VmSession::DEFAULT_NAME);

        if (null === $context->module->getNamedGlobal(self::GLOBAL_ID_BUF)) {
            self::$idBufGlobal = $context->module->addGlobal($idBufType, self::GLOBAL_ID_BUF);
        } else {
            self::$idBufGlobal = $context->module->getNamedGlobal(self::GLOBAL_ID_BUF);
        }

        $standalone = Builtin::LOAD_TYPE_STANDALONE === $context->loadType;

        if (null === $context->module->getNamedGlobal(self::GLOBAL_ID_LEN)) {
            self::$idLenGlobal = $context->module->addGlobal($i64, self::GLOBAL_ID_LEN);
            if (!$standalone) {
                self::$idLenGlobal->setInitializer($i64->constInt(0, false));
            }
        } else {
            self::$idLenGlobal = $context->module->getNamedGlobal(self::GLOBAL_ID_LEN);
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_NAME_BUF)) {
            self::$nameBufGlobal = $context->module->addGlobal($nameBufType, self::GLOBAL_NAME_BUF);
        } else {
            self::$nameBufGlobal = $context->module->getNamedGlobal(self::GLOBAL_NAME_BUF);
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_NAME_LEN)) {
            self::$nameLenGlobal = $context->module->addGlobal($i64, self::GLOBAL_NAME_LEN);
            if (!$standalone) {
                self::$nameLenGlobal->setInitializer($i64->constInt($defaultNameLen, false));
            }
        } else {
            self::$nameLenGlobal = $context->module->getNamedGlobal(self::GLOBAL_NAME_LEN);
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_ACTIVE)) {
            self::$activeGlobal = $context->module->addGlobal($i8, self::GLOBAL_ACTIVE);
            if (!$standalone) {
                self::$activeGlobal->setInitializer($i8->constInt(0, false));
            }
        } else {
            self::$activeGlobal = $context->module->getNamedGlobal(self::GLOBAL_ACTIVE);
        }
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

        if (null === self::$nameLenGlobal) {
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

        $curLen = $context->builder->load(self::$nameLenGlobal);
        $needsSeed = $context->builder->icmp(
            \PHPLLVM\Builder::INT_EQ,
            $curLen,
            $zero
        );
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
