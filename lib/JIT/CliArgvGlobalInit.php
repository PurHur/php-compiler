<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\JIT\Builtin;
use PHPLLVM\Value;

/**
 * Module-global {@code $argv} for standalone AOT CLI binaries (#2794).
 */
final class CliArgvGlobalInit
{
    public static ?Value $global = null;

    public static function initialize(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType
            && Builtin::LOAD_TYPE_EMBED !== $context->loadType) {
            return;
        }
        $valueTy = $context->getTypeFromString('__value__');
        self::$global = $context->module->addGlobal($valueTy, 'jit_global_argv');
        self::$global->setInitializer($valueTy->constNull());
    }

    public static function emitRefreshAfterStoreArgv(Context $context): void
    {
        if (null === self::$global) {
            return;
        }
        $context->builder->call(
            $context->lookupFunction('__phpc_cli_refresh_argv_global'),
            $context->builder->pointerCast(
                self::$global,
                $context->getTypeFromString('__value__*')
            )
        );
    }

    public static function load(Context $context): Variable
    {
        if (null === self::$global) {
            throw new \LogicException('CLI argv global not initialized for JIT');
        }
        // Lazily populate `$argv` on first access, rather than from `main`.
        // Some standalone AOT binaries crash when allocating hashtables during early startup,
        // even after runtime init; defer to the user-level CLI entry reading `$argv` (#2930, #2967).
        self::emitRefreshAfterStoreArgv($context);

        return new Variable(
            $context,
            Variable::TYPE_VALUE,
            Variable::KIND_VARIABLE,
            self::$global
        );
    }
}
