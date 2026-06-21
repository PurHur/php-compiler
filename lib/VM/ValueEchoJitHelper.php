<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Lowered into JIT/AOT modules for echo/print value-box dispatch (#10204, php-in-PHP).
 *
 * SSOT: {@see ValueEchoSupport}
 */
final class ValueEchoJitHelper
{
    public static function typeIsNull(int $typeByte): bool
    {
        return ValueEchoSupport::jitTypeIsNull($typeByte);
    }

    public static function typeIsNativeLong(int $typeByte): bool
    {
        return ValueEchoSupport::jitTypeIsNativeLong($typeByte);
    }

    public static function typeIsNativeBool(int $typeByte): bool
    {
        return ValueEchoSupport::jitTypeIsNativeBool($typeByte);
    }

    public static function typeIsNativeDouble(int $typeByte): bool
    {
        return ValueEchoSupport::jitTypeIsNativeDouble($typeByte);
    }

    public static function typeIsString(int $typeByte): bool
    {
        return ValueEchoSupport::jitTypeIsString($typeByte);
    }

    public static function typeIsHashtable(int $typeByte): bool
    {
        return ValueEchoSupport::jitTypeIsHashtable($typeByte);
    }

    public static function typeIsObject(int $typeByte): bool
    {
        return ValueEchoSupport::jitTypeIsObject($typeByte);
    }

    public static function arrayLabel(): string
    {
        return ValueEchoSupport::ARRAY_LABEL;
    }

    public static function boolTrueLabel(): string
    {
        return ValueEchoSupport::BOOL_TRUE_LABEL;
    }

    public static function objectFallbackLabel(): string
    {
        return ValueEchoSupport::OBJECT_FALLBACK_LABEL;
    }

    public static function resourceFormat(): string
    {
        return ValueEchoSupport::RESOURCE_FORMAT;
    }
}
