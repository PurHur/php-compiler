<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;

/**
 * ini_get/ini_set/ini_restore static storage for compiled JIT/AOT modules (#9249, php-in-PHP).
 *
 * VM SSOT: {@see VmIni} per request context; compiled modules use this helper.
 * php-src: ext/standard/ini.c — PHP_FUNCTION(ini_get) / ini_set / ini_restore
 */
final class IniJitHelper
{
    /** @var list<string> */
    private const SUPPORTED_KEYS = [
        'error_reporting',
        'display_errors',
        'memory_limit',
        'serialize_precision',
        'unserialize_callback_func',
        'session.gc_maxlifetime',
        'include_path',
        'short_open_tag',
        'register_argc_argv',
        'zend.enable_gc',
        'max_execution_time',
        'default_charset',
        'zend.assertions',
        'assert.active',
        'assert.bail',
        'assert.callback',
        'assert.exception',
    ];

    private const ASSERT_INI_KEYS = [
        'zend.assertions',
        'assert.active',
        'assert.bail',
        'assert.callback',
        'assert.exception',
    ];

    private const READONLY_BOOL_DEFAULTS = [
        'short_open_tag' => false,
        'register_argc_argv' => true,
        'zend.enable_gc' => true,
    ];

    private const READONLY_STRING_DEFAULTS = [
        'max_execution_time' => '0',
        'default_charset' => 'UTF-8',
    ];

    private const CFG_DISPLAY_ERRORS = '';

    private const CFG_MEMORY_LIMIT = '-1';

    private const CFG_SERIALIZE_PRECISION = '-1';

    private const CFG_SESSION_GC_MAXLIFETIME = '1440';

    private static bool $displayErrors = false;

    private static string $memoryLimit = self::CFG_MEMORY_LIMIT;

    private static int $serializePrecision = -1;

    private static string $unserializeCallbackFunc = '';

    private static int $sessionGcMaxlifetime = 1440;

    public static function getSerializePrecisionInt(): int
    {
        return self::$serializePrecision;
    }

    /** @return string|null null when ini_get() is false */
    public static function iniGet(string $option): ?string
    {
        $key = strtolower($option);
        if (isset(self::READONLY_BOOL_DEFAULTS[$key])) {
            return VmIni::formatBoolIniGet(self::READONLY_BOOL_DEFAULTS[$key]);
        }
        if (isset(self::READONLY_STRING_DEFAULTS[$key])) {
            return self::READONLY_STRING_DEFAULTS[$key];
        }
        if (in_array($key, self::ASSERT_INI_KEYS, true)) {
            return self::assertIniGet($key);
        }
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return null;
        }

        return match ($key) {
            'error_reporting' => ErrorSilenceJitHelper::iniGetErrorReporting(),
            'display_errors' => VmIni::formatBoolIniGet(self::$displayErrors),
            'memory_limit' => self::$memoryLimit,
            'serialize_precision' => (string) self::$serializePrecision,
            'unserialize_callback_func' => self::$unserializeCallbackFunc,
            'session.gc_maxlifetime' => (string) self::$sessionGcMaxlifetime,
            'include_path' => IncludePathJitHelper::get(),
            default => null,
        };
    }

    /** @return string|null null when ini_set() is false */
    public static function iniSet(string $option, string $newValue): ?string
    {
        $key = strtolower($option);
        if (in_array($key, self::ASSERT_INI_KEYS, true)) {
            return self::assertIniSet($key, $newValue);
        }
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return null;
        }

        return match ($key) {
            'error_reporting' => self::setErrorReporting($newValue),
            'display_errors' => self::setDisplayErrors($newValue),
            'memory_limit' => self::setMemoryLimit($newValue),
            'serialize_precision' => self::setSerializePrecision($newValue),
            'unserialize_callback_func' => self::setUnserializeCallbackFunc($newValue),
            'session.gc_maxlifetime' => self::setSessionGcMaxlifetime($newValue),
            'include_path' => IncludePathJitHelper::push($newValue),
            default => null,
        };
    }

    /** @return string|null null when get_cfg_var() is false */
    public static function iniCfgGet(string $option): ?string
    {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return null;
        }

        return match ($key) {
            'error_reporting' => (string) ErrorReporter::DEFAULT_STARTUP_REPORTING,
            'display_errors' => self::CFG_DISPLAY_ERRORS,
            'memory_limit' => self::CFG_MEMORY_LIMIT,
            'serialize_precision' => self::CFG_SERIALIZE_PRECISION,
            'unserialize_callback_func' => '',
            'session.gc_maxlifetime' => self::CFG_SESSION_GC_MAXLIFETIME,
            'max_execution_time' => self::READONLY_STRING_DEFAULTS['max_execution_time'],
            'default_charset' => self::READONLY_STRING_DEFAULTS['default_charset'],
            default => isset(self::READONLY_BOOL_DEFAULTS[$key])
                ? VmIni::formatBoolIniGet(self::READONLY_BOOL_DEFAULTS[$key])
                : null,
        };
    }

    public static function iniRestore(string $option): void
    {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return;
        }

        switch ($key) {
            case 'error_reporting':
                ErrorSilenceJitHelper::iniRestoreErrorReporting();
                break;
            case 'display_errors':
                self::$displayErrors = VmIni::parseBoolIni(self::CFG_DISPLAY_ERRORS);
                break;
            case 'memory_limit':
                self::$memoryLimit = self::CFG_MEMORY_LIMIT;
                break;
            case 'serialize_precision':
                self::$serializePrecision = VmIni::parseSerializePrecision(self::CFG_SERIALIZE_PRECISION);
                break;
            case 'unserialize_callback_func':
                self::$unserializeCallbackFunc = '';
                break;
            case 'session.gc_maxlifetime':
                self::$sessionGcMaxlifetime = (int) self::CFG_SESSION_GC_MAXLIFETIME;
                break;
        }
    }

    private static function setErrorReporting(string $newValue): string
    {
        $old = ErrorSilenceJitHelper::iniGetErrorReporting();
        ErrorSilenceJitHelper::setErrorReporting(VmIni::parseErrorReporting($newValue));

        return $old;
    }

    private static function setDisplayErrors(string $newValue): string
    {
        $old = VmIni::formatBoolIniGet(self::$displayErrors);
        self::$displayErrors = VmIni::parseBoolIni($newValue);

        return $old;
    }

    private static function setMemoryLimit(string $newValue): string
    {
        $old = self::$memoryLimit;
        self::$memoryLimit = $newValue;

        return $old;
    }

    private static function setSerializePrecision(string $newValue): string
    {
        $old = (string) self::$serializePrecision;
        self::$serializePrecision = VmIni::parseSerializePrecision($newValue);

        return $old;
    }

    private static function setUnserializeCallbackFunc(string $newValue): string
    {
        $old = self::$unserializeCallbackFunc;
        self::$unserializeCallbackFunc = $newValue;

        return $old;
    }

    /** @return string|null null when ini_set rejected the value */
    private static function setSessionGcMaxlifetime(string $newValue): ?string
    {
        $parsed = (int) trim($newValue);
        if ($parsed <= 0) {
            return null;
        }
        $old = (string) self::$sessionGcMaxlifetime;
        self::$sessionGcMaxlifetime = $parsed;

        return $old;
    }

    /** @return string|null */
    private static function assertIniGet(string $key): ?string
    {
        return match ($key) {
            'zend.assertions' => AssertOptionsJitHelper::iniGetZendAssertions(),
            'assert.active' => AssertOptionsJitHelper::iniGetActive(),
            'assert.bail' => AssertOptionsJitHelper::getBailInt() ? '1' : '0',
            'assert.callback' => AssertOptionsJitHelper::getCallbackString(),
            'assert.exception' => AssertOptionsJitHelper::iniGetException(),
            default => null,
        };
    }

    /** @return string|null */
    private static function assertIniSet(string $key, string $newValue): ?string
    {
        return match ($key) {
            'zend.assertions' => AssertOptionsJitHelper::iniSetZendAssertionsFromString($newValue),
            'assert.active' => AssertOptionsJitHelper::iniSetActiveFromString($newValue),
            'assert.bail' => (string) AssertOptionsJitHelper::setBailBool(VmIni::parseBoolIni($newValue)),
            'assert.callback' => AssertOptionsJitHelper::setCallbackString($newValue),
            'assert.exception' => AssertOptionsJitHelper::iniSetExceptionFromString($newValue),
            default => null,
        };
    }
}
