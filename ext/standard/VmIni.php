<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/** Minimal ini_set() subset (issue #1374): error_reporting, display_errors, memory_limit, precision, serialize_precision. */
final class VmIni
{
    /** php-src INI_ALL — user/perdir/system readable. */
    private const INI_ACCESS_ALL = 7;

    /** Read-only boolean directives with Zend CLI defaults (ext/standard/ini.c, #11356, #14844). */
    private const READONLY_BOOL_DEFAULTS = [
        'enable_dl' => false,
        'short_open_tag' => false,
        'zend.enable_gc' => true,
        'session.use_cookies' => true,
        'session.use_only_cookies' => true,
        'allow_url_fopen' => true,
        'allow_url_include' => false,
    ];

    /** Read-only string directives with Zend CLI defaults (ext/standard/ini.c, #11357, #14844). */
    private const READONLY_STRING_DEFAULTS = [
        'session.save_handler' => 'files',
        'user_ini.filename' => '.user.ini',
        'realpath_cache_size' => '4096K',
        'realpath_cache_ttl' => '120',
        'post_max_size' => '8M',
        'upload_max_filesize' => '2M',
        'default_socket_timeout' => '60',
        'auto_detect_line_endings' => '0',
        'default_mimetype' => 'text/html',
        'variables_order' => 'GPCS',
        'request_order' => 'GP',
        'arg_separator.output' => '&',
        'from' => '',
        'session.trans_sid_hosts' => '',
        'session.trans_sid_tags' => 'a=href,area=href,frame=src,form=',
        'url_rewriter.hosts' => '',
        'url_rewriter.tags' => 'form=',
        'assert.warning' => '1',
    ];

    /** php-src ext/standard module ini entries (ini.c, #9052). */
    private const STANDARD_EXTENSION_KEYS = [
        'assert.active',
        'assert.bail',
        'assert.callback',
        'assert.exception',
        'assert.warning',
        'auto_detect_line_endings',
        'default_socket_timeout',
        'from',
        'session.trans_sid_hosts',
        'session.trans_sid_tags',
        'unserialize_max_depth',
        'url_rewriter.hosts',
        'url_rewriter.tags',
        'user_agent',
    ];

    /** php-src ext/pcre module ini entries (php_pcre.c, #9052). */
    private const PCRE_EXTENSION_KEYS = [
        'pcre.backtrack_limit',
        'pcre.jit',
        'pcre.recursion_limit',
    ];

    /** php-src php.ini compile-time default for max_execution_time (ext/standard/ini.c, #12481). */
    private const CFG_MAX_EXECUTION_TIME = '0';

    /** php-src PG(default_charset) default UTF-8 (ext/standard/ini.c, INI_ALL, #12531). */
    private const CFG_DEFAULT_CHARSET = 'UTF-8';

    /**
     * Registered string ini directives with no local value — Zend returns '' (#12178).
     *
     * php-src: ext/standard/ini.c — zend_ini_string for unset PG() entries
     *
     * @var list<string>
     */
    public const EMPTY_STRING_INI_KEYS = [
        'auto_prepend_file',
        'auto_append_file',
        'browscap',
        'error_log',
        'doc_root',
        'user_dir',
        'disable_functions',
        'disable_classes',
        'open_basedir',
        'mail.add_x_header',
        'error_append_string',
        'error_prepend_string',
        'upload_tmp_dir',
        'sys_temp_dir',
    ];

    /**
     * get_cfg_var() compile-time keys that return '' when unset (php-src cfg_get_entry, #12543).
     *
     * Other {@see EMPTY_STRING_INI_KEYS} return false from get_cfg_var() — only ini_get() is ''.
     *
     * @var list<string>
     */
    private const CFG_EMPTY_STRING_KEYS = [
        'auto_prepend_file',
        'auto_append_file',
        'doc_root',
        'user_dir',
        'disable_functions',
        'disable_classes',
        'mail.add_x_header',
    ];

    /** @var list<string> */
    public const SUPPORTED_KEYS = [
        'error_reporting',
        'display_errors',
        'memory_limit',
        'precision',
        'serialize_precision',
        'unserialize_max_depth',
        'unserialize_callback_func',
        'session.gc_maxlifetime',
        'session.save_path',
        'session.use_strict_mode',
        'include_path',
        'short_open_tag',
        'register_argc_argv',
        'zend.enable_gc',
        'max_execution_time',
        'default_charset',
        'date.timezone',
        'cfg_file_path',
        'user_agent',
        'pcre.backtrack_limit',
        'pcre.jit',
        'pcre.recursion_limit',
        ...VmAssertState::SUPPORTED_INI_KEYS,
    ];

    /** php-src PG(pcre.backtrack_limit) default 1000000 (ext/pcre/php_pcre.c). */
    private const CFG_PCRE_BACKTRACK_LIMIT = '1000000';

    /** php-src PG(pcre.recursion_limit) default 100000 (ext/pcre/php_pcre.c, #12433). */
    private const CFG_PCRE_RECURSION_LIMIT = '100000';

    private const CFG_DISPLAY_ERRORS = '';

    private const CFG_MEMORY_LIMIT = '-1';

    /** php-src PG(precision) default 14 (ext/standard/ini.c, issue #11841). */
    private const CFG_PRECISION = '14';

    private const CFG_SERIALIZE_PRECISION = '-1';

    /** php-src PG(unserialize_max_depth) default 4096 (ext/standard/ini.c, #13628). */
    private const CFG_UNSERIALIZE_MAX_DEPTH = '4096';

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
            case 'precision':
                return self::setPrecision($newValue);
            case 'serialize_precision':
                return self::setSerializePrecision($newValue);
            case 'unserialize_max_depth':
                return self::setUnserializeMaxDepth($newValue);
            case 'unserialize_callback_func':
                return self::setUnserializeCallbackFunc($newValue);
            case 'session.gc_maxlifetime':
                return self::setSessionGcMaxLifetime($newValue);
            case 'session.save_path':
                return self::setSessionSavePath($newValue);
            case 'session.use_strict_mode':
                return self::setSessionUseStrictMode($newValue);
            case 'include_path':
                return VmIncludePath::push($newValue);
            case 'default_charset':
                return self::setDefaultCharset($newValue);
            case 'date.timezone':
                return self::setDateTimezone($newValue);
            case 'user_agent':
                return self::setUserAgent($newValue);
            case 'pcre.backtrack_limit':
                return self::setPcreBacktrackLimit($newValue);
            case 'pcre.jit':
                return self::setPcreJit($newValue);
            case 'pcre.recursion_limit':
                return self::setPcreRecursionLimit($newValue);
            case 'max_execution_time':
                return self::setMaxExecutionTime($ctx, $newValue);
            case 'register_argc_argv':
                // php-src: PHP_INI_PERDIR — not modifiable after startup (#4515).
                return false;
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
        if ('browscap' === $key) {
            $startup = VmBrowser::startupBrowscapPath();
            if (null !== $startup && '' !== $startup) {
                return $startup;
            }

            return '';
        }
        if (in_array($key, self::EMPTY_STRING_INI_KEYS, true)) {
            return '';
        }
        $mirrored = VmIniIntrospection::mirroredHostIniGet($key);
        if (null !== $mirrored) {
            return $mirrored;
        }
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            $registry = VmIniIntrospection::registryEntry($key);
            if (null !== $registry) {
                $local = $registry['local_value'];

                return null === $local ? '' : $local;
            }

            return false;
        }

        switch ($key) {
            case 'error_reporting':
                return (string) $ctx->errors->getErrorReporting();
            case 'display_errors':
                return self::displayErrorsIniString($ctx);
            case 'memory_limit':
                return self::$memoryLimit;
            case 'precision':
                return (string) self::$precision;
            case 'serialize_precision':
                return (string) self::$serializePrecision;
            case 'unserialize_max_depth':
                return (string) self::$unserializeMaxDepth;
            case 'unserialize_callback_func':
                return self::$unserializeCallbackFunc;
            case 'session.gc_maxlifetime':
                return (string) self::$sessionGcMaxLifetime;
            case 'session.save_path':
                return self::$sessionSavePath;
            case 'session.use_strict_mode':
                return self::formatBoolIniGet(VmSession::isUseStrictMode());
            case 'include_path':
                return VmIncludePath::get();
            case 'default_charset':
                return self::$defaultCharset;
            case 'date.timezone':
                return VmDate::defaultTimezoneGet();
            case 'user_agent':
                return self::$userAgent;
            case 'pcre.backtrack_limit':
                return (string) self::$pcreBacktrackLimit;
            case 'pcre.jit':
                return self::formatBoolIniGet(self::$pcreJit);
            case 'pcre.recursion_limit':
                return (string) self::$pcreRecursionLimit;
            case 'max_execution_time':
                return self::$maxExecutionTime;
            case 'register_argc_argv':
                return self::formatRegisterArgcArgvIniGet(self::$registerArgcArgv);
            default:
                return false;
        }
    }

    /** get_cfg_var() — php.ini compile-time values (ext/standard/ini.c, #6119, #17881). */
    public static function getCfgVar(string $option): string|false
    {
        $key = strtolower($option);
        if (in_array($key, self::CFG_EMPTY_STRING_KEYS, true)) {
            return '';
        }
        if (isset(self::READONLY_BOOL_DEFAULTS[$key])) {
            return self::formatBoolIniGet(self::READONLY_BOOL_DEFAULTS[$key]);
        }
        if (isset(self::READONLY_STRING_DEFAULTS[$key])) {
            return self::READONLY_STRING_DEFAULTS[$key];
        }
        if ('engine' === $key) {
            return '1';
        }
        if ('zend.exception_ignore_args' === $key) {
            return '1';
        }
        if (in_array($key, VmAssertState::SUPPORTED_INI_KEYS, true)) {
            return VmAssertState::iniGet($option);
        }
        if (!in_array($key, self::SUPPORTED_KEYS, true)) {
            return false;
        }

        return match ($key) {
            'error_reporting' => self::defaultErrorReportingString(),
            'display_errors' => self::CFG_DISPLAY_ERRORS,
            'memory_limit' => self::CFG_MEMORY_LIMIT,
            'precision' => self::CFG_PRECISION,
            'serialize_precision' => self::CFG_SERIALIZE_PRECISION,
            'unserialize_max_depth' => self::CFG_UNSERIALIZE_MAX_DEPTH,
            'unserialize_callback_func' => '',
            'session.gc_maxlifetime' => self::CFG_SESSION_GC_MAXLIFETIME,
            'session.save_path' => self::CFG_SESSION_SAVE_PATH,
            'session.use_strict_mode' => '0',
            'max_execution_time' => self::CFG_MAX_EXECUTION_TIME,
            'default_charset' => self::CFG_DEFAULT_CHARSET,
            'cfg_file_path' => self::cfgFilePath(),
            'user_agent' => '',
            'pcre.backtrack_limit' => self::CFG_PCRE_BACKTRACK_LIMIT,
            'pcre.jit' => '1',
            'pcre.recursion_limit' => self::CFG_PCRE_RECURSION_LIMIT,
            'register_argc_argv' => self::formatRegisterArgcArgvIniGet(self::$registerArgcArgv),
            default => false,
        };
    }

    /** php-src cfg_file_path — path of loaded php.ini (ext/standard/info.c, #10179). */
    private static function cfgFilePath(): string|false
    {
        return VmIniIntrospection::loadedFile();
    }

    /** php-src PG(precision) — float display/significant digits (ext/standard/ini.c, #11841). */
    public static function getPrecision(): int
    {
        return self::$precision;
    }

    /** php-src PG(serialize_precision) default -1 (zend_dtoa mode 0; issue #7100). */
    public static function getSerializePrecision(): string
    {
        return (string) self::$serializePrecision;
    }

    /** php-src PG(unserialize_max_depth) (ext/standard/ini.c, #13628). */
    public static function getUnserializeMaxDepth(): int
    {
        return self::$unserializeMaxDepth;
    }

    /** Raw ini_set() value for display_errors; null uses php.ini default formatting (#11835). */
    private static ?string $displayErrorsLocalValue = null;

    private static string $memoryLimit = self::CFG_MEMORY_LIMIT;

    private static int $precision = 14;

    private static int $serializePrecision = -1;

    private static int $unserializeMaxDepth = 4096;

    private static string $unserializeCallbackFunc = '';

    private static int $sessionGcMaxLifetime = 1440;

    private static string $sessionSavePath = self::CFG_SESSION_SAVE_PATH;

    private static string $userAgent = '';

    private static string $defaultCharset = self::CFG_DEFAULT_CHARSET;

    private static int $pcreBacktrackLimit = 1_000_000;

    private static bool $pcreJit = true;

    private static int $pcreRecursionLimit = 100_000;

    private static string $maxExecutionTime = self::CFG_MAX_EXECUTION_TIME;

    /** php-src PG(register_argc_argv) — startup/-d only; runtime ini_set() returns false (#4515). */
    private static bool $registerArgcArgv = true;

    /** True when CLI SAPI should define $argc/$argv (php-src main.c, issue #4374). */
    public static function registerArgcArgvEnabled(): bool
    {
        return self::$registerArgcArgv;
    }

    /**
     * Apply php.ini / -d overrides before SAPI argv population (ext/standard/ini.c, #4515).
     *
     * @return bool true when the key was applied as a startup-only directive
     */
    public static function applyStartupIniOverride(string $option, string $value): bool
    {
        $key = strtolower($option);
        if ('register_argc_argv' === $key) {
            self::$registerArgcArgv = self::parseBoolIni($value);
            IniJitHelper::syncRegisterArgcArgv(self::$registerArgcArgv);

            return true;
        }
        if ('browscap' === $key) {
            VmBrowser::setStartupBrowscapPath($value);

            return true;
        }
        if ('phar.readonly' === $key) {
            \PHPCompiler\ext\phar\VmPhar::setStartupReadonly(self::parseBoolIni($value));

            return true;
        }

        return false;
    }

    /** Observable ini_get('max_execution_time') after set_time_limit / ini_set (#12481). */
    public static function syncMaxExecutionTime(int $seconds): void
    {
        self::$maxExecutionTime = (string) $seconds;
    }

    /** Stored ini_get('max_execution_time') without VM context — timer bootstrap (#15906). */
    public static function getStoredMaxExecutionTime(): string
    {
        return self::$maxExecutionTime;
    }

    public static function getPcreBacktrackLimit(): int
    {
        return self::$pcreBacktrackLimit;
    }

    public static function getPcreJit(): bool
    {
        return self::$pcreJit;
    }

    public static function getPcreRecursionLimit(): int
    {
        return self::$pcreRecursionLimit;
    }

    public static function getUserAgent(): string
    {
        return self::$userAgent;
    }

    public static function getDefaultCharset(): string
    {
        return self::$defaultCharset;
    }

    public static function getSessionGcMaxLifetime(): int
    {
        return self::$sessionGcMaxLifetime;
    }

    public static function getSessionSavePath(): string
    {
        return self::$sessionSavePath;
    }

    public static function setSessionSavePathValue(string $newValue): string
    {
        $old = self::$sessionSavePath;
        self::$sessionSavePath = $newValue;

        return $old;
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

    private static function setPrecision(string $newValue) {
        $old = (string) self::$precision;
        self::$precision = self::parsePrecision($newValue);

        return $old;
    }

    private static function setSerializePrecision(string $newValue) {
        $old = (string) self::$serializePrecision;
        self::$serializePrecision = self::parseSerializePrecision($newValue);

        return $old;
    }

    /** @return string|false */
    private static function setUnserializeMaxDepth(string $newValue): string|false
    {
        $parsed = (int) trim($newValue);
        if ($parsed <= 0) {
            return false;
        }
        $old = (string) self::$unserializeMaxDepth;
        self::$unserializeMaxDepth = $parsed;

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

    /** php-src session.use_strict_mode (#21155). */
    private static function setSessionUseStrictMode(string $newValue): string
    {
        $old = self::formatBoolIniGet(VmSession::isUseStrictMode());
        VmSession::setUseStrictMode(self::parseBoolIni($newValue));

        return $old;
    }

    private static function setUserAgent(string $newValue): string
    {
        $old = self::$userAgent;
        self::$userAgent = $newValue;

        return $old;
    }

    private static function setDefaultCharset(string $newValue): string
    {
        $old = self::$defaultCharset;
        self::$defaultCharset = $newValue;

        return $old;
    }

    /** @return string|false */
    private static function setDateTimezone(string $newValue): string|false
    {
        $old = VmDate::defaultTimezoneGet();
        if (!VmDate::tryDefaultTimezoneSet($newValue)) {
            return false;
        }

        return $old;
    }

    public static function getUnserializeCallbackFunc(): string
    {
        return self::$unserializeCallbackFunc;
    }

    public static function parsePrecision(string $value): int
    {
        return (int) trim($value);
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

    /** register_argc_argv ini_get() always returns "0" or "1" (ext/standard/ini.c, #4515). */
    public static function formatRegisterArgcArgvIniGet(bool $on): string
    {
        return $on ? '1' : '0';
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
            case 'precision':
                self::$precision = self::parsePrecision(self::CFG_PRECISION);
                break;
            case 'serialize_precision':
                self::$serializePrecision = self::parseSerializePrecision(self::CFG_SERIALIZE_PRECISION);
                break;
            case 'unserialize_max_depth':
                self::$unserializeMaxDepth = (int) self::CFG_UNSERIALIZE_MAX_DEPTH;
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
            case 'session.use_strict_mode':
                VmSession::setUseStrictMode(false);
                break;
            case 'user_agent':
                self::$userAgent = '';
                break;
            case 'default_charset':
                self::$defaultCharset = self::CFG_DEFAULT_CHARSET;
                break;
            case 'pcre.backtrack_limit':
                self::$pcreBacktrackLimit = (int) self::CFG_PCRE_BACKTRACK_LIMIT;
                break;
            case 'pcre.jit':
                self::$pcreJit = true;
                break;
            case 'pcre.recursion_limit':
                self::$pcreRecursionLimit = (int) self::CFG_PCRE_RECURSION_LIMIT;
                break;
            case 'max_execution_time':
                self::$maxExecutionTime = self::CFG_MAX_EXECUTION_TIME;
                $ctx->executionLimits->applyMaxExecutionTime((int) self::CFG_MAX_EXECUTION_TIME);
                break;
            case 'register_argc_argv':
                self::$registerArgcArgv = true;
                IniJitHelper::syncRegisterArgcArgv(true);
                break;
        }
    }

    /** @return string|false */
    private static function setPcreBacktrackLimit(string $newValue): string|false
    {
        $old = (string) self::$pcreBacktrackLimit;
        $parsed = (int) $newValue;
        if ($parsed < 0) {
            return false;
        }
        self::$pcreBacktrackLimit = $parsed;

        return $old;
    }

    private static function setPcreJit(string $newValue): string|false
    {
        $old = self::formatBoolIniGet(self::$pcreJit);
        self::$pcreJit = self::parseBoolIni($newValue);

        return $old;
    }

    private static function setPcreRecursionLimit(string $newValue): string|false
    {
        $old = (string) self::$pcreRecursionLimit;
        $parsed = (int) $newValue;
        if ($parsed < 0) {
            return false;
        }
        self::$pcreRecursionLimit = $parsed;

        return $old;
    }

    /** @return string|false */
    private static function setMaxExecutionTime(Context $ctx, string $newValue): string|false
    {
        $parsed = (int) trim($newValue);
        $old = self::$maxExecutionTime;
        $ctx->executionLimits->applyMaxExecutionTime($parsed);

        return $old;
    }

    /** Known ini_get_all() extension filters (ext/standard/ini.c, #9052, #16433). */
    public static function isKnownIniExtension(string $extension): bool
    {
        if (VmIniIntrospection::isKnownIniExtension($extension)) {
            return true;
        }

        return null !== self::keysForExtension($extension);
    }

    /**
     * ini_get_all() — introspection for supported directives (ext/standard/ini.c, #3205).
     *
     * @return HashTable|false
     */
    public static function getAll(Context $ctx, ?string $extension, bool $details, ?Frame $frame = null)
    {
        $keys = self::keysForExtension($extension);
        if (null === $keys) {
            $ctx->errors->triggerError(
                'ini_get_all(): Extension "'.$extension.'" cannot be found',
                ErrorReporter::E_WARNING,
                null,
                $ctx,
                $frame
            );

            return false;
        }

        $result = new HashTable();
        foreach ($keys as $key) {
            $local = self::get($ctx, $key);
            if (false === $local) {
                continue;
            }
            if ($details) {
                $entry = new HashTable();
                $global = self::detailGlobalValue($ctx, $key);
                $local = self::detailLocalValue($ctx, $key);
                $access = self::INI_ACCESS_ALL;
                $registry = VmIniIntrospection::registryEntry($key);
                if (null !== $registry) {
                    $access = $registry['access'];
                }
                $entry->add('global_value', self::detailVar($global));
                $entry->add('local_value', self::detailVar($local));
                $entry->add('access', self::intVar($access));
                $slot = new Variable();
                $slot->array($entry);
                $result->add($key, $slot);
            } else {
                $result->add($key, self::stringVar($local));
            }
        }

        return $result;
    }

    /** @return list<string>|null */
    private static function keysForExtension(?string $extension): ?array
    {
        $registry = VmIniIntrospection::registryKeysForExtension($extension);
        if (null !== $registry) {
            return $registry;
        }

        if (null === $extension) {
            return self::allStaticRegistryKeys();
        }

        return match (strtolower($extension)) {
            'core' => self::allStaticRegistryKeys(),
            'standard' => self::STANDARD_EXTENSION_KEYS,
            'pcre' => self::PCRE_EXTENSION_KEYS,
            default => null,
        };
    }

    /** Static fallback when host ini registry is unavailable (#16433). */
    private static function allStaticRegistryKeys(): array
    {
        return array_values(array_unique(array_merge(
            self::SUPPORTED_KEYS,
            array_keys(self::READONLY_BOOL_DEFAULTS),
            array_keys(self::READONLY_STRING_DEFAULTS),
            self::EMPTY_STRING_INI_KEYS,
            VmIniIntrospection::MIRRORED_HOST_INI_KEYS,
            ['engine', 'zend.exception_ignore_args'],
        )));
    }

    private static function detailLocalValue(Context $ctx, string $key): ?string
    {
        if ('assert.callback' === $key) {
            return AssertOptionsJitHelper::getCallbackForOptions();
        }

        $local = self::get($ctx, $key);
        if (false === $local) {
            $registry = VmIniIntrospection::registryEntry($key);

            return null !== $registry ? $registry['local_value'] : null;
        }

        return self::coalesceIniGetAllDetailValue($key, $local, 'local_value');
    }

    private static function detailGlobalValue(Context $ctx, string $key): ?string
    {
        if ('assert.callback' === $key) {
            return AssertOptionsJitHelper::getCallbackForOptions();
        }

        $global = self::getCfgVar($key);
        if (false === $global) {
            return self::detailLocalValue($ctx, $key);
        }

        return self::coalesceIniGetAllDetailValue($key, $global, 'global_value');
    }

    /**
     * php-src ini_get_all() exposes NULL for unset PG() entries while ini_get() returns ''.
     *
     * @param 'global_value'|'local_value' $slot
     */
    private static function coalesceIniGetAllDetailValue(string $key, string $value, string $slot): ?string
    {
        if ('' !== $value) {
            return $value;
        }

        $registry = VmIniIntrospection::registryEntry($key);
        if (null !== $registry && null === $registry[$slot]) {
            return null;
        }

        return $value;
    }

    private static function detailVar(?string $value): Variable
    {
        if (null === $value) {
            return self::nullVar();
        }

        return self::stringVar($value);
    }

    private static function nullVar(): Variable
    {
        $var = new Variable();
        $var->null();

        return $var;
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
