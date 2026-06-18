<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

/**
 * assert() / assert_options() INI and runtime state (ext/standard/assert.c; issue #3316).
 *
 * php-src: ext/standard/assert.c, Zend/zend_API.c (zend.assertions)
 */
final class VmAssertState
{
    public const INI_ZEND_ASSERTIONS = 'zend.assertions';

    public const INI_ASSERT_ACTIVE = 'assert.active';

    public const INI_ASSERT_BAIL = 'assert.bail';

    public const INI_ASSERT_CALLBACK = 'assert.callback';

    public const INI_ASSERT_EXCEPTION = 'assert.exception';

    /** @var list<string> */
    public const SUPPORTED_INI_KEYS = [
        self::INI_ZEND_ASSERTIONS,
        self::INI_ASSERT_ACTIVE,
        self::INI_ASSERT_BAIL,
        self::INI_ASSERT_CALLBACK,
        self::INI_ASSERT_EXCEPTION,
    ];

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

    /** @return string|false */
    public static function iniGet(string $option)
    {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_INI_KEYS, true)) {
            return false;
        }

        return match ($key) {
            self::INI_ZEND_ASSERTIONS => (string) self::$zendAssertions,
            self::INI_ASSERT_ACTIVE => self::$active ? '1' : '0',
            self::INI_ASSERT_BAIL => self::$bail ? '1' : '0',
            self::INI_ASSERT_EXCEPTION => self::$exception ? '1' : '0',
            self::INI_ASSERT_CALLBACK => self::$callback ?? '',
            default => false,
        };
    }

    /** @return string|false */
    public static function iniSet(string $option, string $value)
    {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_INI_KEYS, true)) {
            return false;
        }

        $old = self::iniGet($key);
        if (false === $old) {
            return false;
        }

        switch ($key) {
            case self::INI_ZEND_ASSERTIONS:
                self::$zendAssertions = (int) $value;
                break;
            case self::INI_ASSERT_ACTIVE:
                self::$active = VmIni::parseBoolIni($value);
                break;
            case self::INI_ASSERT_BAIL:
                self::$bail = VmIni::parseBoolIni($value);
                break;
            case self::INI_ASSERT_EXCEPTION:
                self::$exception = VmIni::parseBoolIni($value);
                break;
            case self::INI_ASSERT_CALLBACK:
                self::$callback = '' === trim($value) ? null : $value;
                break;
        }

        return $old;
    }

    /**
     * @return int|string|bool|false
     */
    public static function getOption(int $what)
    {
        return match ($what) {
            StdlibConstants::ASSERT_ACTIVE => self::$active ? 1 : 0,
            StdlibConstants::ASSERT_CALLBACK => self::$callback ?? '',
            StdlibConstants::ASSERT_BAIL => self::$bail ? 1 : 0,
            StdlibConstants::ASSERT_WARNING => false,
            StdlibConstants::ASSERT_EXCEPTION => self::$exception ? 1 : 0,
            default => false,
        };
    }

    /**
     * @return int|string|bool|false
     */
    public static function setOption(int $what, Variable $value)
    {
        $old = self::getOption($what);
        if (false === $old && StdlibConstants::ASSERT_WARNING !== $what) {
            return false;
        }

        switch ($what) {
            case StdlibConstants::ASSERT_ACTIVE:
                self::$active = boolval::isTruthy($value);
                break;
            case StdlibConstants::ASSERT_BAIL:
                self::$bail = boolval::isTruthy($value);
                break;
            case StdlibConstants::ASSERT_EXCEPTION:
                self::$exception = boolval::isTruthy($value);
                break;
            case StdlibConstants::ASSERT_CALLBACK:
                if (Variable::TYPE_STRING !== $value->resolveIndirect()->type) {
                    return false;
                }
                $str = $value->resolveIndirect()->toString();
                self::$callback = '' === $str ? null : $str;
                break;
            case StdlibConstants::ASSERT_WARNING:
                return false;
            default:
                return false;
        }

        return $old;
    }
}
