<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\SapiOutput;

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
        'session.save_path',
        'include_path',
        'short_open_tag',
        'register_argc_argv',
        'zend.enable_gc',
        'max_execution_time',
        'default_charset',
        'cfg_file_path',
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

    private const CFG_SESSION_SAVE_PATH = '/var/lib/php/sessions';

    private static bool $displayErrors = false;

    private static string $memoryLimit = self::CFG_MEMORY_LIMIT;

    private static int $serializePrecision = -1;

    private static string $unserializeCallbackFunc = '';

    private static int $sessionGcMaxlifetime = 1440;

    private static string $sessionSavePath = self::CFG_SESSION_SAVE_PATH;

    public static function getSerializePrecisionInt(): int
    {
        return self::$serializePrecision;
    }

    private static function parseSerializePrecisionIni(string $newValue): int
    {
        $trimmed = trim($newValue);
        if ('' === $trimmed) {
            return -1;
        }

        return intval($trimmed);
    }

    private static function parseSessionGcMaxlifetimeIni(string $newValue): int
    {
        return intval(trim($newValue));
    }

    private static function serializePrecisionAsIniString(): string
    {
        return \sprintf('%d', self::$serializePrecision);
    }

    private static function sessionGcMaxlifetimeAsIniString(): string
    {
        return \sprintf('%d', self::$sessionGcMaxlifetime);
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

        if ('error_reporting' === $key) {
            return ErrorSilenceJitHelper::iniGetErrorReporting();
        }
        if ('display_errors' === $key) {
            return VmIni::formatBoolIniGet(self::$displayErrors);
        }
        if ('memory_limit' === $key) {
            return self::$memoryLimit;
        }
        if ('serialize_precision' === $key) {
            return self::serializePrecisionAsIniString();
        }
        if ('unserialize_callback_func' === $key) {
            return self::$unserializeCallbackFunc;
        }
        if ('session.gc_maxlifetime' === $key) {
            return self::sessionGcMaxlifetimeAsIniString();
        }
        if ('session.save_path' === $key) {
            return self::$sessionSavePath;
        }
        if ('include_path' === $key) {
            return IncludePathJitHelper::get();
        }

        return null;
    }

    /** @return string|null null when ini_set() is false */
    public static function iniSet(string $option, string $newValue): ?string
    {
        $key = strtolower($option);
        if (self::rejectSessionIniAfterHeadersSent($key)) {
            return null;
        }
        if (in_array($key, self::ASSERT_INI_KEYS, true)) {
            return self::assertIniSet($key, $newValue);
        }
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return null;
        }

        if ('error_reporting' === $key) {
            return self::setErrorReporting($newValue);
        }
        if ('display_errors' === $key) {
            return self::setDisplayErrors($newValue);
        }
        if ('memory_limit' === $key) {
            return self::setMemoryLimit($newValue);
        }
        if ('serialize_precision' === $key) {
            return self::setSerializePrecision($newValue);
        }
        if ('unserialize_callback_func' === $key) {
            return self::setUnserializeCallbackFunc($newValue);
        }
        if ('session.gc_maxlifetime' === $key) {
            return self::setSessionGcMaxlifetime($newValue);
        }
        if ('session.save_path' === $key) {
            return self::setSessionSavePath($newValue);
        }
        if ('include_path' === $key) {
            return IncludePathJitHelper::push($newValue);
        }

        return null;
    }

    /** @return string|null null when get_cfg_var() is false */
    public static function iniCfgGet(string $option): ?string
    {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return null;
        }

        if ('error_reporting' === $key) {
            return \sprintf('%d', ErrorReporter::DEFAULT_STARTUP_REPORTING);
        }
        if ('display_errors' === $key) {
            return self::CFG_DISPLAY_ERRORS;
        }
        if ('memory_limit' === $key) {
            return self::CFG_MEMORY_LIMIT;
        }
        if ('serialize_precision' === $key) {
            return self::CFG_SERIALIZE_PRECISION;
        }
        if ('unserialize_callback_func' === $key) {
            return '';
        }
        if ('session.gc_maxlifetime' === $key) {
            return self::CFG_SESSION_GC_MAXLIFETIME;
        }
        if ('session.save_path' === $key) {
            return self::CFG_SESSION_SAVE_PATH;
        }
        if ('max_execution_time' === $key) {
            return self::READONLY_STRING_DEFAULTS['max_execution_time'];
        }
        if ('default_charset' === $key) {
            return self::READONLY_STRING_DEFAULTS['default_charset'];
        }
        if ('cfg_file_path' === $key) {
            $path = VmIniIntrospection::loadedFile();

            return false === $path ? null : $path;
        }
        if (isset(self::READONLY_BOOL_DEFAULTS[$key])) {
            return VmIni::formatBoolIniGet(self::READONLY_BOOL_DEFAULTS[$key]);
        }

        return null;
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
                self::$serializePrecision = self::parseSerializePrecisionIni(self::CFG_SERIALIZE_PRECISION);
                break;
            case 'unserialize_callback_func':
                self::$unserializeCallbackFunc = '';
                break;
            case 'session.gc_maxlifetime':
                self::$sessionGcMaxlifetime = 1440;
                break;
            case 'session.save_path':
                self::$sessionSavePath = self::CFG_SESSION_SAVE_PATH;
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
        $old = self::serializePrecisionAsIniString();
        self::$serializePrecision = self::parseSerializePrecisionIni($newValue);

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
        $parsed = self::parseSessionGcMaxlifetimeIni($newValue);
        if ($parsed <= 0) {
            return null;
        }
        $old = self::sessionGcMaxlifetimeAsIniString();
        self::$sessionGcMaxlifetime = $parsed;

        return $old;
    }

    /** @return string|null null when ini_set rejected the value */
    private static function setSessionSavePath(string $newValue): ?string
    {
        $old = self::$sessionSavePath;
        self::$sessionSavePath = $newValue;

        return $old;
    }

    /** @return string|null */
    private static function assertIniGet(string $key): ?string
    {
        if ('zend.assertions' === $key) {
            return AssertOptionsJitHelper::iniGetZendAssertions();
        }
        if ('assert.active' === $key) {
            return AssertOptionsJitHelper::iniGetActive();
        }
        if ('assert.bail' === $key) {
            if (AssertOptionsJitHelper::getBailInt()) {
                return '1';
            }

            return '0';
        }
        if ('assert.callback' === $key) {
            return AssertOptionsJitHelper::getCallbackString();
        }
        if ('assert.exception' === $key) {
            return AssertOptionsJitHelper::iniGetException();
        }

        return null;
    }

    /** @return string|null */
    private static function assertIniSet(string $key, string $newValue): ?string
    {
        if ('zend.assertions' === $key) {
            return AssertOptionsJitHelper::iniSetZendAssertionsFromString($newValue);
        }
        if ('assert.active' === $key) {
            return AssertOptionsJitHelper::iniSetActiveFromString($newValue);
        }
        if ('assert.bail' === $key) {
            $old = AssertOptionsJitHelper::setBailBool(VmIni::parseBoolIni($newValue));
            if ($old) {
                return '1';
            }

            return '0';
        }
        if ('assert.callback' === $key) {
            return AssertOptionsJitHelper::setCallbackString($newValue);
        }
        if ('assert.exception' === $key) {
            return AssertOptionsJitHelper::iniSetExceptionFromString($newValue);
        }

        return null;
    }

    /** php-src ext/session/session.c — session ini cannot change after headers sent (#11548). */
    private static function rejectSessionIniAfterHeadersSent(string $key): bool
    {
        if (!SapiOutput::headersSent()) {
            return false;
        }

        return in_array($key, ['session.save_path', 'session.gc_maxlifetime'], true);
    }
}
