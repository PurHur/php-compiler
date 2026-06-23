<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM global for runtime late-static called class id (#4792, #10247).
 *
 * VM source of truth: {@see \PHPCompiler\VM\LateStaticBinding}
 * php-src: Zend/zend_execute.c — get_called_scope()
 */
final class LateStaticBindingGlobals
{
    public const GLOBAL_CLASS_ID = 'phpc_late_static_class_id';

    /** @var Value|null */
    public static $classIdGlobal = null;

    /** @var object|null LLVM module identity — static Value must not leak across Context instances */
    private static $classIdModule = null;

    public static function ensureGlobal(Context $context): Value
    {
        $module = $context->module;
        if (null !== self::$classIdGlobal && self::$classIdModule === $module) {
            return self::$classIdGlobal;
        }

        $existing = $module->getNamedGlobal(self::GLOBAL_CLASS_ID);
        if (null !== $existing) {
            self::$classIdGlobal = $existing;
            self::$classIdModule = $module;

            return self::$classIdGlobal;
        }

        $i64 = $context->getTypeFromString('int64');
        self::$classIdGlobal = $module->addGlobal($i64, self::GLOBAL_CLASS_ID);
        self::$classIdGlobal->setInitializer($i64->constInt(0, false));
        self::$classIdModule = $module;

        return self::$classIdGlobal;
    }

    public static function emitStore(Context $context, Value $classId): void
    {
        $context->builder->store($classId, self::ensureGlobal($context));
    }

    public static function emitLoad(Context $context): Value
    {
        return $context->builder->load(self::ensureGlobal($context));
    }
}
