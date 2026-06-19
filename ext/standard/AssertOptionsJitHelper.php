<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * assert_options() / assert INI static storage for compiled JIT/AOT modules (#9513, php-in-PHP).
 *
 * VM SSOT delegates here via {@see VmAssertState}.
 * php-src: ext/standard/assert.c — PHP_FUNCTION(assert_options)
 */
final class AssertOptionsJitHelper
{
    private static int $zendAssertions = -1;

    private static bool $active = true;

    private static bool $exception = true;

    private static bool $bail = false;

    private static ?string $callback = null;

    public static function isEnabled(): bool
    {
        return self::$zendAssertions > 0 && self::$active;
    }

    public static function shouldThrowOnFailure(): bool
    {
        return self::$exception;
    }

    public static function shouldBailOnFailure(): bool
    {
        return self::$bail;
    }

    public static function getActiveInt(): int
    {
        return self::$active ? 1 : 0;
    }

    public static function setActiveBool(bool $value): int
    {
        $old = self::getActiveInt();
        self::$active = $value;

        return $old;
    }

    public static function getBailInt(): int
    {
        return self::$bail ? 1 : 0;
    }

    public static function setBailBool(bool $value): int
    {
        $old = self::getBailInt();
        self::$bail = $value;

        return $old;
    }

    public static function getExceptionInt(): int
    {
        return self::$exception ? 1 : 0;
    }

    public static function setExceptionBool(bool $value): int
    {
        $old = self::getExceptionInt();
        self::$exception = $value;

        return $old;
    }

    public static function getCallbackString(): string
    {
        return self::$callback ?? '';
    }

    public static function setCallbackString(string $value): string
    {
        $old = self::getCallbackString();
        self::$callback = '' === $value ? null : $value;

        return $old;
    }

    public static function iniGetZendAssertions(): string
    {
        return (string) self::$zendAssertions;
    }

    public static function iniSetZendAssertionsFromString(string $value): string
    {
        $old = self::iniGetZendAssertions();
        self::$zendAssertions = (int) $value;

        return $old;
    }

    public static function iniGetActive(): string
    {
        return self::$active ? '1' : '0';
    }

    public static function iniSetActiveFromString(string $value): string
    {
        $old = self::iniGetActive();
        self::$active = self::parseBoolIni($value);

        return $old;
    }

    public static function iniGetException(): string
    {
        return self::$exception ? '1' : '0';
    }

    public static function iniSetExceptionFromString(string $value): string
    {
        $old = self::iniGetException();
        self::$exception = self::parseBoolIni($value);

        return $old;
    }

    private static function parseBoolIni(string $value): bool
    {
        $trimmed = strtolower(trim($value));

        return !('' === $trimmed || '0' === $trimmed || 'off' === $trimmed || 'false' === $trimmed);
    }
}
