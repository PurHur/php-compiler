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
    public static $htGlobal = null;

    public static function implement(Context $context): void
    {
        if (null !== self::$htGlobal) {
            return;
        }
        $htPtr = $context->getTypeFromString('__hashtable__*');
        self::$htGlobal = $context->module->addGlobal($htPtr, self::GLOBAL_HT);
        self::$htGlobal->setInitializer($htPtr->constNull());
    }

    public static function emitStore(Context $context, Variable $packedArgv): void
    {
        self::implement($context);
        $context->builder->store(
            $context->helper->loadValue($packedArgv),
            self::$htGlobal
        );
    }

    public static function load(Context $context): Value
    {
        self::implement($context);

        return $context->builder->load(self::$htGlobal);
    }
}
