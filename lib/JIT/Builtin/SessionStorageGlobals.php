<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\VmSession;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM globals for JIT/AOT session_id() / session_name() buffers (issues #1183–#1184, #5694).
 *
 * VM source of truth: {@see VmSession}. MCJIT defines globals here; standalone AOT links
 * the same symbols from {@see lib/AOT/runtime/phpc_session_state.c} until #5332.
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

        if (null === $context->module->getNamedGlobal(self::GLOBAL_ID_BUF)) {
            self::$idBufGlobal = $context->module->addGlobal($idBufType, self::GLOBAL_ID_BUF);
        } else {
            self::$idBufGlobal = $context->module->getNamedGlobal(self::GLOBAL_ID_BUF);
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_ID_LEN)) {
            self::$idLenGlobal = $context->module->addGlobal($i64, self::GLOBAL_ID_LEN);
            if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
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
            if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
                $defaultLen = \strlen(VmSession::DEFAULT_NAME);
                self::$nameLenGlobal->setInitializer($i64->constInt($defaultLen, false));
            }
        } else {
            self::$nameLenGlobal = $context->module->getNamedGlobal(self::GLOBAL_NAME_LEN);
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_ACTIVE)) {
            self::$activeGlobal = $context->module->addGlobal($i8, self::GLOBAL_ACTIVE);
            if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
                self::$activeGlobal->setInitializer($i8->constInt(0, false));
            }
        } else {
            self::$activeGlobal = $context->module->getNamedGlobal(self::GLOBAL_ACTIVE);
        }
    }
}
