<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Minimal ini_set() subset (issue #1374): error_reporting, display_errors, memory_limit, serialize_precision. */
final class VmIni
{
    /** php-src INI_ALL — user/perdir/system readable. */
    private const INI_ACCESS_ALL = 7;

    /** Read-only boolean directives with Zend CLI defaults (ext/standard/ini.c, #11356). */
    private const READONLY_BOOL_DEFAULTS = [
        'short_open_tag' => false,
        'register_argc_argv' => true,
        'zend.enable_gc' => true,
    ];

    /** Read-only string directives with Zend CLI defaults (ext/standard/ini.c, #11357). */
    private const READONLY_STRING_DEFAULTS = [
        'max_execution_time' => '0',
        'default_charset' => 'UTF-8',
    ];

    /** @var list<string> */
    public const SUPPORTED_KEYS = [
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
        ...VmAssertState::SUPPORTED_INI_KEYS,
    ];

    private const CFG_DISPLAY_ERRORS = '';

    private const CFG_MEMORY_LIMIT = '-1';

    private const CFG_SERIALIZE_PRECISION = '-1';

    private const CFG_SESSION_GC_MAXLIFETIME = '1440';

    /** php-src ext/session/session.c — PG(session_save_path) default on Linux CLI. */
    private const CFG_SESSION_SAVE_PATH = '/var/lib/php/sessions';

    public static function set(Context $ctx, string $option, string $newValue) {
        $key = strtolower($option);
        if (in_array($key, VmAssertState::SUPPORTED_INI_KEYS, true)) {
            return VmAssertState::iniSet($option, $newValue);
        }
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return false;
        }

        switch ($key) {
            case 'error_reporting':
                return self::setErrorReporting($ctx, $newValue);
            case 'display_errors':
                return self::setDisplayErrors($ctx, $newValue);
            case 'memory_limit':
                return self::setMemoryLimit($newValue);
            case 'serialize_precision':
                return self::setSerializePrecision($newValue);
            case 'unserialize_callback_func':
                return self::setUnserializeCallbackFunc($newValue);
            case 'session.gc_maxlifetime':
                return self::setSessionGcMaxLifetime($newValue);
            case 'session.save_path':
                return self::setSessionSavePath($newValue);
            case 'include_path':
                return IncludePathJitHelper::push($newValue);
            default:
                return false;
        }
    }

    /** @return string|false */
    public static function get(Context $ctx, string $option) {
        $key = strtolower($option);
        if (isset(self::READONLY_BOOL_DEFAULTS[$key])) {
            return self::formatBoolIniGet(self::READONLY_BOOL_DEFAULTS[$key]);
        }
        if (isset(self::READONLY_STRING_DEFAULTS[$key])) {
            return self::READONLY_STRING_DEFAULTS[$key];
        }
        if (in_array($key, VmAssertState::SUPPORTED_INI_KEYS, true)) {
            return VmAssertState::iniGet($option);
        }
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return false;
        }

        switch ($key) {
            case 'error_reporting':
                return (string) $ctx->errors->getErrorReporting();
            case 'display_errors':
                return self::displayErrorsIniString($ctx);
            case 'memory_limit':
                return self::$memoryLimit;
            case 'serialize_precision':
                return (string) self::$serializePrecision;
            case 'unserialize_callback_func':
                return self::$unserializeCallbackFunc;
            case 'session.gc_maxlifetime':
                return (string) self::$sessionGcMaxLifetime;
            case 'session.save_path':
                return self::$sessionSavePath;
            case 'include_path':
                return IncludePathJitHelper::get();
            default:
                return false;
        }
    }

    /** get_cfg_var() — php.ini compile-time values (ext/standard/ini.c, #6119). */
    public static function getCfgVar(string $option): string|false
    {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return false;
        }

        return match ($key) {
            'error_reporting' => self::defaultErrorReportingString(),
            'display_errors' => self::CFG_DISPLAY_ERRORS,
            'memory_limit' => self::CFG_MEMORY_LIMIT,
            'serialize_precision' => self::CFG_SERIALIZE_PRECISION,
            'unserialize_callback_func' => '',
            'session.gc_maxlifetime' => self::CFG_SESSION_GC_MAXLIFETIME,
            'session.save_path' => self::CFG_SESSION_SAVE_PATH,
            'max_execution_time' => self::READONLY_STRING_DEFAULTS['max_execution_time'],
            'default_charset' => self::READONLY_STRING_DEFAULTS['default_charset'],
            'cfg_file_path' => self::cfgFilePath(),
            default => false,
        };
    }

    /** php-src cfg_file_path — path of loaded php.ini (ext/standard/info.c, #10179). */
    private static function cfgFilePath(): string|false
    {
        return VmIniIntrospection::loadedFile();
    }

    /** php-src PG(serialize_precision) default -1 (zend_dtoa mode 0; issue #7100). */
    public static function getSerializePrecision(): string
    {
        return (string) self::$serializePrecision;
    }

    /** Raw ini_set() value for display_errors; null uses php.ini default formatting (#11835). */
    private static ?string $displayErrorsLocalValue = null;

    private static string $memoryLimit = self::CFG_MEMORY_LIMIT;

    private static int $serializePrecision = -1;

    private static string $unserializeCallbackFunc = '';

    private static int $sessionGcMaxLifetime = 1440;

    private static string $sessionSavePath = self::CFG_SESSION_SAVE_PATH;

    public static function getSessionGcMaxLifetime(): int
    {
        return self::$sessionGcMaxLifetime;
    }

    private static function setErrorReporting(Context $ctx, string $newValue) {
        $old = (string) $ctx->errors->getErrorReporting();
        $ctx->errors->setErrorReporting(self::parseErrorReporting($newValue));

        return $old;
    }

    private static function setDisplayErrors(Context $ctx, string $newValue) {
        $old = self::displayErrorsIniString($ctx);
        self::$displayErrorsLocalValue = $newValue;
        $ctx->errors->setDisplayErrors(self::parseBoolIni($newValue));

        return $old;
    }

    private static function displayErrorsIniString(Context $ctx): string
    {
        if (null !== self::$displayErrorsLocalValue) {
            return self::$displayErrorsLocalValue;
        }

        return self::formatBoolIniGet($ctx->errors->getDisplayErrors());
    }

    private static function setMemoryLimit(string $newValue) {
        $old = self::$memoryLimit;
        self::$memoryLimit = $newValue;

        return $old;
    }

    private static function setSerializePrecision(string $newValue) {
        $old = (string) self::$serializePrecision;
        self::$serializePrecision = self::parseSerializePrecision($newValue);

        return $old;
    }

    private static function setUnserializeCallbackFunc(string $newValue) {
        $old = self::$unserializeCallbackFunc;
        self::$unserializeCallbackFunc = $newValue;

        return $old;
    }

    private static function setSessionGcMaxLifetime(string $newValue) {
        $parsed = (int) trim($newValue);
        if ($parsed <= 0) {
            return false;
        }
        $old = (string) self::$sessionGcMaxLifetime;
        self::$sessionGcMaxLifetime = $parsed;

        return $old;
    }

    private static function setSessionSavePath(string $newValue) {
        $old = self::$sessionSavePath;
        self::$sessionSavePath = $newValue;

        return $old;
    }

    public static function getUnserializeCallbackFunc(): string
    {
        return self::$unserializeCallbackFunc;
    }

    public static function parseSerializePrecision(string $value): int
    {
        $trimmed = trim($value);

        return '' === $trimmed ? -1 : (int) $trimmed;
    }

    public static function errorReporting(Context $ctx, ?int $newLevel = null): int
    {
        $old = $ctx->errors->getErrorReporting();
        if (null !== $newLevel) {
            $ctx->errors->setErrorReporting($newLevel);
        }

        return $old;
    }

    public static function parseErrorReporting(string $value): int
    {
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return 0;
        }
        $constant = Context::errorReportingConstant($trimmed);
        if (null !== $constant) {
            return $constant;
        }

        return (int) $trimmed;
    }

    public static function parseBoolIni(string $value): bool
    {
        $trimmed = strtolower(trim($value));

        return !('' === $trimmed || '0' === $trimmed || 'off' === $trimmed || 'false' === $trimmed);
    }

    /** php-src zend_ini.c — boolean ini_get() display as "" or "1" (#11356). */
    public static function formatBoolIniGet(bool $on): string
    {
        return $on ? '1' : '';
    }

    /**
     * ini_restore() — reset local value to php.ini global default (ext/standard/ini.c, #3205).
     */
    public static function restore(Context $ctx, string $option): void
    {
        $key = strtolower($option);
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return;
        }

        switch ($key) {
            case 'error_reporting':
                $ctx->errors->setErrorReporting(self::parseErrorReporting(self::defaultErrorReportingString()));
                break;
            case 'display_errors':
                self::$displayErrorsLocalValue = null;
                $ctx->errors->setDisplayErrors(self::parseBoolIni(self::CFG_DISPLAY_ERRORS));
                break;
            case 'memory_limit':
                self::$memoryLimit = self::CFG_MEMORY_LIMIT;
                break;
            case 'serialize_precision':
                self::$serializePrecision = self::parseSerializePrecision(self::CFG_SERIALIZE_PRECISION);
                break;
            case 'unserialize_callback_func':
                self::$unserializeCallbackFunc = '';
                break;
            case 'session.gc_maxlifetime':
                self::$sessionGcMaxLifetime = (int) self::CFG_SESSION_GC_MAXLIFETIME;
                break;
            case 'session.save_path':
                self::$sessionSavePath = self::CFG_SESSION_SAVE_PATH;
                break;
        }
    }

    /**
     * ini_get_all() — introspection for supported directives (ext/standard/ini.c, #3205).
     *
     * @return HashTable|false
     */
    public static function getAll(Context $ctx, ?string $extension, bool $details)
    {
        if (null !== $extension && 'core' !== strtolower($extension)) {
            return false;
        }

        $result = new HashTable();
        foreach (self::SUPPORTED_KEYS as $key) {
            $local = self::get($ctx, $key);
            if (false === $local) {
                continue;
            }
            if ($details) {
                $entry = new HashTable();
                $global = self::getCfgVar($key);
                if (false === $global) {
                    $global = $local;
                }
                $entry->add('global_value', self::stringVar($global));
                $entry->add('local_value', self::stringVar($local));
                $entry->add('access', self::intVar(self::INI_ACCESS_ALL));
                $slot = new Variable();
                $slot->array($entry);
                $result->add($key, $slot);
            } else {
                $result->add($key, self::stringVar($local));
            }
        }

        return $result;
    }

    private static function stringVar(string $value): Variable
    {
        $var = new Variable();
        $var->string($value);

        return $var;
    }

    private static function intVar(int $value): Variable
    {
        $var = new Variable();
        $var->int($value);

        return $var;
    }

    private static function defaultErrorReportingString(): string
    {
        return (string) ErrorReporter::DEFAULT_STARTUP_REPORTING;
    }
}
