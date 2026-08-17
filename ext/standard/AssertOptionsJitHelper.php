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
    /**
     * Fallback before CLI host sync / {@code -d} / PHPT {@code --INI--}.
     * Production php.ini is typically {@code -1}; {@see php_compiler_cli_sync_host_zend_assertions}
     * mirrors the host process (#31195). Explicit startup {@code 1} enables assert() (#28823).
     */
    private static int $zendAssertions = -1;

    private static bool $active = true;

    private static bool $exception = true;

    private static bool $bail = false;

    private static ?string $callback = null;

    public static function isEnabled(): bool
    {
        return self::$zendAssertions > 0 && self::$active;
    }

    /**
     * php-src {@code zend_compile_assert}: {@code EG(assertions) < 0} compiles {@code assert()}
     * to constant {@code true} and does not evaluate arguments (#31857, Zend/zend_compile.c).
     */
    public static function shouldCompileOutAssert(): bool
    {
        return self::$zendAssertions < 0;
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
        if (null === self::$callback) {
            return '';
        }

        return self::$callback;
    }

    /** assert_options(ASSERT_CALLBACK) get — null when unset (php-src RETURN_NULL). */
    public static function getCallbackForOptions(): ?string
    {
        return self::$callback;
    }

    public static function hasCallback(): int
    {
        return null === self::$callback ? 0 : 1;
    }

    public static function setCallbackString(string $value): string
    {
        $old = self::getCallbackString();
        self::$callback = '' === $value ? null : $value;

        return $old;
    }

    /** php-src Zend/zend.c OnUpdateAssertions — E_WARNING text. */
    public const MSG_ZEND_ASSERTIONS_PHP_INI_ONLY =
        'zend.assertions may be completely enabled or disabled only in php.ini';

    /** php-src ext/standard/assert.c — assert_options() unknown $option (#30524). */
    public const MSG_INVALID_OPTION =
        'assert_options(): Argument #1 ($option) must be an ASSERT_* constant';

    public static function iniGetZendAssertions(): string
    {
        return (string) self::$zendAssertions;
    }

    /**
     * CLI {@code -d zend.assertions=} / PHPT {@code --INI--} startup stage (#24396).
     *
     * php-src allows crossing -1 only at ZEND_INI_STAGE_STARTUP / SHUTDOWN.
     */
    public static function applyStartupZendAssertions(string $value): void
    {
        self::$zendAssertions = (int) $value;
    }

    /**
     * Runtime ini_set('zend.assertions') — php-src OnUpdateAssertions (#24396).
     *
     * Crossing to/from a negative value at runtime is rejected (null → ini_set false).
     * Callers emit the Zend E_WARNING via VM ErrorReporter or TriggerErrorJitHelper.
     * Toggling between 0 and 1 (or no-op same value) remains allowed.
     *
     * @return string|null Previous value, or null when rejected
     */
    public static function iniSetZendAssertionsFromString(string $value): ?string
    {
        $oldInt = self::$zendAssertions;
        $newInt = (int) $value;
        if ($oldInt !== $newInt && ($oldInt < 0 || $newInt < 0)) {
            return null;
        }
        self::$zendAssertions = $newInt;

        return (string) $oldInt;
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
