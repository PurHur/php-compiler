<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * assert() INI accessors for JIT/AOT via AssertOptionsJitHelper PHP (#9513, #9894).
 *
 * Thin emit helpers — no phpc_assert ABI indirection; bodies in {@see AssertOptionsRuntime}.
 * php-src: ext/standard/assert.c
 */
final class AssertIniRuntime
{
    public static function ensureGlobals(Context $context): void
    {
        AssertOptionsRuntime::ensureAssertIniLinked($context);
    }

    public static function loadAssertionsEnabled(Context $context): Value
    {
        return AssertOptionsRuntime::emitLoadBoolHelper($context, AssertOptionsRuntime::IS_ENABLED, false);
    }

    public static function loadExceptionMode(Context $context): Value
    {
        return AssertOptionsRuntime::emitLoadBoolHelper($context, AssertOptionsRuntime::EXCEPTION_MODE, true);
    }

    public static function writeIniGetZendAssertions(Context $context, Value $out): void
    {
        AssertOptionsRuntime::emitIniGetToValue($context, AssertOptionsRuntime::INI_GET_ZEND_ASSERTIONS, $out, '-1');
    }

    public static function writeIniGetActive(Context $context, Value $out): void
    {
        AssertOptionsRuntime::emitIniGetToValue($context, AssertOptionsRuntime::INI_GET_ACTIVE, $out, '1');
    }

    public static function writeIniGetException(Context $context, Value $out): void
    {
        AssertOptionsRuntime::emitIniGetToValue($context, AssertOptionsRuntime::INI_GET_EXCEPTION, $out, '1');
    }

    public static function applyIniSetZendAssertions(Context $context, Value $fn, Value $valCstr): void
    {
        AssertOptionsRuntime::emitIniSetFromCstr($context, AssertOptionsRuntime::INI_SET_ZEND_ASSERTIONS, $valCstr);
    }

    public static function applyIniSetActive(Context $context, Value $fn, Value $valCstr): void
    {
        AssertOptionsRuntime::emitIniSetFromCstr($context, AssertOptionsRuntime::INI_SET_ACTIVE, $valCstr);
    }

    public static function applyIniSetException(Context $context, Value $fn, Value $valCstr): void
    {
        AssertOptionsRuntime::emitIniSetFromCstr($context, AssertOptionsRuntime::INI_SET_EXCEPTION, $valCstr);
    }
}
