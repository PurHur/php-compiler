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

    public static function isEnabled(): bool
    {
        return AssertOptionsJitHelper::isEnabled();
    }

    public static function shouldThrowOnFailure(): bool
    {
        return AssertOptionsJitHelper::shouldThrowOnFailure();
    }

    public static function shouldBailOnFailure(): bool
    {
        return AssertOptionsJitHelper::shouldBailOnFailure();
    }

    /** @return string|false */
    public static function iniGet(string $option)
    {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_INI_KEYS, true)) {
            return false;
        }

        return match ($key) {
            self::INI_ZEND_ASSERTIONS => AssertOptionsJitHelper::iniGetZendAssertions(),
            self::INI_ASSERT_ACTIVE => AssertOptionsJitHelper::iniGetActive(),
            self::INI_ASSERT_BAIL => AssertOptionsJitHelper::getBailInt() ? '1' : '0',
            self::INI_ASSERT_EXCEPTION => AssertOptionsJitHelper::iniGetException(),
            self::INI_ASSERT_CALLBACK => AssertOptionsJitHelper::getCallbackString(),
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
                AssertOptionsJitHelper::iniSetZendAssertionsFromString($value);
                break;
            case self::INI_ASSERT_ACTIVE:
                AssertOptionsJitHelper::iniSetActiveFromString($value);
                break;
            case self::INI_ASSERT_BAIL:
                AssertOptionsJitHelper::setBailBool(VmIni::parseBoolIni($value));
                break;
            case self::INI_ASSERT_EXCEPTION:
                AssertOptionsJitHelper::iniSetExceptionFromString($value);
                break;
            case self::INI_ASSERT_CALLBACK:
                AssertOptionsJitHelper::setCallbackString('' === trim($value) ? '' : $value);
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
            StdlibConstants::ASSERT_ACTIVE => AssertOptionsJitHelper::getActiveInt(),
            StdlibConstants::ASSERT_CALLBACK => AssertOptionsJitHelper::getCallbackString(),
            StdlibConstants::ASSERT_BAIL => AssertOptionsJitHelper::getBailInt(),
            StdlibConstants::ASSERT_WARNING => false,
            StdlibConstants::ASSERT_EXCEPTION => AssertOptionsJitHelper::getExceptionInt(),
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
                AssertOptionsJitHelper::setActiveBool(boolval::isTruthy($value));
                break;
            case StdlibConstants::ASSERT_BAIL:
                AssertOptionsJitHelper::setBailBool(boolval::isTruthy($value));
                break;
            case StdlibConstants::ASSERT_EXCEPTION:
                AssertOptionsJitHelper::setExceptionBool(boolval::isTruthy($value));
                break;
            case StdlibConstants::ASSERT_CALLBACK:
                if (Variable::TYPE_STRING !== $value->resolveIndirect()->type) {
                    return false;
                }
                $str = $value->resolveIndirect()->toString();
                AssertOptionsJitHelper::setCallbackString('' === $str ? '' : $str);
                break;
            case StdlibConstants::ASSERT_WARNING:
                return false;
            default:
                return false;
        }

        return $old;
    }
}
