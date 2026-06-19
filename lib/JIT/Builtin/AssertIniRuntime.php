<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * assert() INI accessors for JIT/AOT via AssertOptionsJitHelper PHP (#9513).
 *
 * IniRuntime and JitAssert call thin ABI symbols; bodies live in {@see AssertOptionsRuntime}.
 * php-src: ext/standard/assert.c
 */
final class AssertIniRuntime
{
    public const ABI_ENABLED = '__phpc_assert_enabled';

    public const ABI_EXCEPTION_MODE = '__phpc_assert_exception_mode';

    public const ABI_INI_GET_ZEND_ASSERTIONS = '__phpc_assert_ini_get_zend_assertions';

    public const ABI_INI_GET_ACTIVE = '__phpc_assert_ini_get_active';

    public const ABI_INI_GET_EXCEPTION = '__phpc_assert_ini_get_exception';

    public const ABI_INI_SET_ZEND_ASSERTIONS = '__phpc_assert_ini_set_zend_assertions';

    public const ABI_INI_SET_ACTIVE = '__phpc_assert_ini_set_active';

    public const ABI_INI_SET_EXCEPTION = '__phpc_assert_ini_set_exception';

    /** @var list<string> */
    public const ABI_FUNCTIONS = [
        self::ABI_ENABLED,
        self::ABI_EXCEPTION_MODE,
        self::ABI_INI_GET_ZEND_ASSERTIONS,
        self::ABI_INI_GET_ACTIVE,
        self::ABI_INI_GET_EXCEPTION,
        self::ABI_INI_SET_ZEND_ASSERTIONS,
        self::ABI_INI_SET_ACTIVE,
        self::ABI_INI_SET_EXCEPTION,
    ];

    public static function ensureGlobals(Context $context): void
    {
        self::ensureAbiDeclarations($context);
    }

    public static function loadAssertionsEnabled(Context $context): Value
    {
        return $context->builder->call($context->lookupFunction(self::ABI_ENABLED));
    }

    public static function loadExceptionMode(Context $context): Value
    {
        return $context->builder->call($context->lookupFunction(self::ABI_EXCEPTION_MODE));
    }

    public static function writeIniGetZendAssertions(Context $context, Value $out): void
    {
        $context->builder->call($context->lookupFunction(self::ABI_INI_GET_ZEND_ASSERTIONS), $out);
    }

    public static function writeIniGetActive(Context $context, Value $out): void
    {
        $context->builder->call($context->lookupFunction(self::ABI_INI_GET_ACTIVE), $out);
    }

    public static function writeIniGetException(Context $context, Value $out): void
    {
        $context->builder->call($context->lookupFunction(self::ABI_INI_GET_EXCEPTION), $out);
    }

    public static function applyIniSetZendAssertions(Context $context, Value $fn, Value $valCstr): void
    {
        $context->builder->call($context->lookupFunction(self::ABI_INI_SET_ZEND_ASSERTIONS), $valCstr);
    }

    public static function applyIniSetActive(Context $context, Value $fn, Value $valCstr): void
    {
        $context->builder->call($context->lookupFunction(self::ABI_INI_SET_ACTIVE), $valCstr);
    }

    public static function applyIniSetException(Context $context, Value $fn, Value $valCstr): void
    {
        $context->builder->call($context->lookupFunction(self::ABI_INI_SET_EXCEPTION), $valCstr);
    }

    private static function ensureAbiDeclarations(Context $context): void
    {
        $i1 = $context->getTypeFromString('int1');
        $i8p = $context->getTypeFromString('int8*');
        $valPtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            [self::ABI_ENABLED, $context->context->functionType($i1, false)],
            [self::ABI_EXCEPTION_MODE, $context->context->functionType($i1, false)],
            [self::ABI_INI_GET_ZEND_ASSERTIONS, $context->context->functionType($voidTy, false, $valPtr)],
            [self::ABI_INI_GET_ACTIVE, $context->context->functionType($voidTy, false, $valPtr)],
            [self::ABI_INI_GET_EXCEPTION, $context->context->functionType($voidTy, false, $valPtr)],
            [self::ABI_INI_SET_ZEND_ASSERTIONS, $context->context->functionType($voidTy, false, $i8p)],
            [self::ABI_INI_SET_ACTIVE, $context->context->functionType($voidTy, false, $i8p)],
            [self::ABI_INI_SET_EXCEPTION, $context->context->functionType($voidTy, false, $i8p)],
        ] as [$name, $ft]) {
            if (null !== $context->module->getNamedFunction($name)) {
                continue;
            }
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
