<?php

declare(strict_types=1);

/**
 * Call-site argv for func_get_args / func_num_args (issue #197).
 *
 * Stored in a module global at each user-function LLVM call (single-threaded;
 * recursive introspection is not supported under JIT in this compiler build).
 */

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

final class CallArgv
{
    public const GLOBAL_HT = '__phpc_call_argv_ht';

    /** @var Value|null */
    private static $htGlobal = null;

    /** @var object|null LLVM module identity — static Value must not leak across Context instances */
    private static $htModule = null;

    public static function implement(Context $context): void
    {
        self::ensureGlobal($context);
    }

    public static function emitStore(Context $context, Variable $packedArgv): void
    {
        $context->builder->store(
            $context->helper->loadValue($packedArgv),
            self::ensureGlobal($context)
        );
    }

    public static function load(Context $context): Value
    {
        return $context->builder->load(self::ensureGlobal($context));
    }

    private static function ensureGlobal(Context $context): Value
    {
        $module = $context->module;
        if (null !== self::$htGlobal && self::$htModule === $module) {
            return self::$htGlobal;
        }

        $existing = $module->getNamedGlobal(self::GLOBAL_HT);
        if (null !== $existing) {
            self::$htGlobal = $existing;
            self::$htModule = $module;

            return self::$htGlobal;
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::$htGlobal = $module->addGlobal($htPtr, self::GLOBAL_HT);
        self::$htGlobal->setInitializer($htPtr->constNull());
        self::$htModule = $module;

        return self::$htGlobal;
    }
}
