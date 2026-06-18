<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * assert() INI globals for JIT/AOT (ext/standard/assert.c; issue #3316).
 */
final class AssertIniRuntime
{
    public const G_ZEND_ASSERTIONS = 'phpc_assert_zend_assertions';

    public const G_ASSERT_ACTIVE = 'phpc_assert_active';

    public const G_ASSERT_EXCEPTION = 'phpc_assert_exception';

    public const G_ASSERT_BAIL = 'phpc_assert_bail';

    public const G_ASSERT_CALLBACK = 'phpc_assert_callback';

    public static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $defaults = [
            self::G_ZEND_ASSERTIONS => -1,
            self::G_ASSERT_ACTIVE => 1,
            self::G_ASSERT_EXCEPTION => 1,
            self::G_ASSERT_BAIL => 0,
        ];
        foreach ($defaults as $name => $value) {
            if (null === $context->module->getNamedGlobal($name)) {
                $global = $context->module->addGlobal($i32, $name);
                $global->setInitializer($i32->constInt($value, false));
            }
        }
        if (null === $context->module->getNamedGlobal(self::G_ASSERT_CALLBACK)) {
            $cb = $context->module->addGlobal($i8p, self::G_ASSERT_CALLBACK);
            $cb->setInitializer($i8p->constNull());
        }
    }

    public static function globalPtr(Context $context, string $name): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $global = $context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException("Missing assert ini global {$name}");
        }

        return $context->builder->pointerCast($global, $i32->pointerType(0));
    }

    public static function callbackGlobalPtr(Context $context): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $global = $context->module->getNamedGlobal(self::G_ASSERT_CALLBACK);
        if (null === $global) {
            throw new \LogicException('Missing assert ini global '.self::G_ASSERT_CALLBACK);
        }

        return $context->builder->pointerCast($global, $i8p->pointerType(0));
    }

    public static function loadAssertionsEnabled(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $zend = $context->builder->load(self::globalPtr($context, self::G_ZEND_ASSERTIONS));
        $active = $context->builder->load(self::globalPtr($context, self::G_ASSERT_ACTIVE));
        $zendOn = $context->builder->icmp(Builder::INT_SGT, $zend, $i32->constInt(0, false));
        $activeOn = $context->builder->icmp(Builder::INT_NE, $active, $i32->constInt(0, false));

        return $context->builder->and($zendOn, $activeOn);
    }

    public static function loadExceptionMode(Context $context): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $mode = $context->builder->load(self::globalPtr($context, self::G_ASSERT_EXCEPTION));

        return $context->builder->icmp(Builder::INT_NE, $mode, $i32->constInt(0, false));
    }
}
