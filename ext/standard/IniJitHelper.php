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
    /**
     * NestedJIT cannot return typed null for ?string under thin AOT (TypeError abort).
     * Bridge maps this sentinel to Zend false (#33059).
     */
    public const INI_FALSE_SENTINEL = '__phpc_ini_false__';

    /** @var list<string> */
    private const SUPPORTED_KEYS = [
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
        'open_basedir',
        'short_open_tag',
        'register_argc_argv',
        'zend.enable_gc',
        'max_execution_time',
        'default_charset',
        'date.timezone',
        'cfg_file_path',
        'user_agent',
        'url_rewriter.tags',
        'url_rewriter.hosts',
        'pcre.backtrack_limit',
        'pcre.jit',
        'pcre.recursion_limit',
        'zend.exception_string_param_max_len',
        'zend.exception_ignore_args',
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
        'enable_dl' => false,
        'short_open_tag' => false,
        'zend.enable_gc' => true,
        'session.use_cookies' => true,
        'session.use_only_cookies' => true,
        'allow_url_fopen' => true,
        'allow_url_include' => false,
    ];

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
    ];

    private const CFG_MAX_EXECUTION_TIME = '0';

    private const CFG_DEFAULT_CHARSET = 'UTF-8';

    /** Unset string ini directives return '' — mirror VmIni empty-string key list for JIT compile. */
    private const EMPTY_STRING_INI_KEYS = [
        'auto_prepend_file',
        'auto_append_file',
        'browscap',
        'error_log',
        'doc_root',
        'user_dir',
        'disable_functions',
        'disable_classes',
        'mail.add_x_header',
        'error_append_string',
        'error_prepend_string',
        'upload_tmp_dir',
        'sys_temp_dir',
    ];

    /** get_cfg_var() compile-time keys that return '' when unset (#12543, #17881). */
    private const CFG_EMPTY_STRING_KEYS = [
        'auto_prepend_file',
        'auto_append_file',
        'doc_root',
        'user_dir',
        'disable_functions',
        'disable_classes',
        'mail.add_x_header',
    ];

    private const CFG_DISPLAY_ERRORS = '';

    private const CFG_MEMORY_LIMIT = '-1';

    private const CFG_MAX_MEMORY_LIMIT = '-1';

    private const CFG_PRECISION = '14';

    private const CFG_SERIALIZE_PRECISION = '-1';

    private const CFG_UNSERIALIZE_MAX_DEPTH = '4096';

    private const CFG_SESSION_GC_MAXLIFETIME = '1440';

    private const CFG_SESSION_SAVE_PATH = '/var/lib/php/sessions';

    private const CFG_PCRE_BACKTRACK_LIMIT = '1000000';

    private const CFG_PCRE_RECURSION_LIMIT = '100000';

    /** php-src EG(exception_string_param_max_len) compiled default 15 (#21999 / #30626). */
    private const CFG_EXCEPTION_STRING_PARAM_MAX_LEN = 15;

    private const EXCEPTION_STRING_PARAM_MAX_LEN_CEILING = 1_000_000;

    /**
     * php-src compiled default Off (`"0"`) — Zend/zend.c STD_ZEND_INI_BOOLEAN (#28061).
     * php.ini-production sets On; `php -n` / php:8.x-cli keep Off.
     */
    private const CFG_EXCEPTION_IGNORE_ARGS = false;

    private static bool $displayErrors = false;

    /** Raw ini_set() value; null uses php.ini default formatting (#11835). */
    private static ?string $displayErrorsLocalValue = null;

    private static string $memoryLimit = self::CFG_MEMORY_LIMIT;

    private static string $maxMemoryLimit = self::CFG_MAX_MEMORY_LIMIT;

    private static int $precision = 14;

    private static int $serializePrecision = -1;

    private static int $unserializeMaxDepth = 4096;

    private static string $unserializeCallbackFunc = '';

    private static int $sessionGcMaxlifetime = 1440;

    private static string $sessionSavePath = self::CFG_SESSION_SAVE_PATH;

    private static string $userAgent = '';

    private static string $defaultCharset = self::CFG_DEFAULT_CHARSET;

    private static int $pcreBacktrackLimit = 1_000_000;

    private static bool $pcreJit = true;

    private static int $pcreRecursionLimit = 100_000;

    private static int $exceptionStringParamMaxLen = self::CFG_EXCEPTION_STRING_PARAM_MAX_LEN;

    private static bool $exceptionIgnoreArgs = self::CFG_EXCEPTION_IGNORE_ARGS;

    private static string $maxExecutionTime = self::CFG_MAX_EXECUTION_TIME;

    private static bool $registerArgcArgv = true;

    public static function syncRegisterArgcArgv(bool $enabled): void
    {
        self::$registerArgcArgv = $enabled;
    }

    /** Keep NestedJIT/AOT aligned with VmIni max_memory_limit (#23232). */
    public static function syncMaxMemoryLimit(string $value): void
    {
        self::$maxMemoryLimit = $value;
    }

    /** Keep NestedJIT/AOT aligned with VmIni memory_limit string (#23232). */
    public static function syncMemoryLimitString(string $value): void
    {
        self::$memoryLimit = $value;
    }

    /** Keep NestedJIT/AOT aligned with VmIni runtime value (#21999). */
    public static function syncExceptionStringParamMaxLen(int $maxLen): void
    {
        self::$exceptionStringParamMaxLen = $maxLen;
    }

    public static function getExceptionStringParamMaxLen(): int
    {
        return self::$exceptionStringParamMaxLen;
    }

    /** Keep NestedJIT/AOT aligned with VmIni runtime value (#21998). */
    public static function syncExceptionIgnoreArgs(bool $ignore): void
    {
        self::$exceptionIgnoreArgs = $ignore;
    }

    public static function exceptionIgnoreArgsEnabled(): bool
    {
        return self::$exceptionIgnoreArgs;
    }

    public static function registerArgcArgvEnabled(): bool
    {
        return self::$registerArgcArgv;
    }



    public static function getUserAgent(): string

    {
        return self::$userAgent;
    }

    public static function getSerializePrecisionInt(): int
    {
        return self::$serializePrecision;
    }

    /** php-src PG(precision) — int for NestedJIT float→string (#21963). */
    public static function getPrecisionInt(): int
    {
        return self::$precision;
    }

    /** Sync from VM ini_set path ({@see VmIni}); keeps JIT helpers aligned (#21963). */
    public static function syncPrecision(int $precision): void
    {
        self::$precision = $precision;
    }

    public static function getUnserializeMaxDepthInt(): int
    {
        return self::$unserializeMaxDepth;
    }

    public static function syncMaxExecutionTime(int $seconds): void
    {
        self::$maxExecutionTime = (string) $seconds;
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

    private static function precisionAsIniString(): string
    {
        return \sprintf('%d', self::$precision);
    }

    private static function sessionGcMaxlifetimeAsIniString(): string
    {
        return \sprintf('%d', self::$sessionGcMaxlifetime);
    }

    private static function unserializeMaxDepthAsIniString(): string
    {
        return \sprintf('%d', self::$unserializeMaxDepth);
    }

    /** @return string|null null when ini_get() is false */
    public static function iniGet(string $option)
    {
        $key = strtolower($option);
        if ('max_memory_limit' === $key) {
            return \PHPCompiler\CompilerVersion::supportsMaxMemoryLimit() ? self::$maxMemoryLimit : self::INI_FALSE_SENTINEL;
        }
        // NestedJIT isset()/in_array()/nullable returns are BSS-toxic under thin AOT (#33059).
        if (self::isReadonlyBoolKey($key)) {
            return VmIni::formatBoolIniGet(self::readonlyBoolDefault($key));
        }
        if (self::isReadonlyStringKey($key)) {
            return self::readonlyStringDefault($key);
        }
        if (self::isAssertIniKey($key)) {
            return self::assertIniGet($key);
        }
        if (self::isEmptyStringIniKey($key)) {
            return '';
        }
        if (self::isMirroredHostIniKey($key)) {
            $mirrored = VmIniIntrospection::mirroredHostIniGet($key);
            if (\is_string($mirrored)) {
                return $mirrored;
            }

            return self::INI_FALSE_SENTINEL;
        }
        if (!self::isSupportedKey($key)) {
            return self::INI_FALSE_SENTINEL;
        }

        if ('error_reporting' === $key) {
            return ErrorSilenceJitHelper::iniGetErrorReporting();
        }
        if ('display_errors' === $key) {
            return self::displayErrorsIniString();
        }
        if ('memory_limit' === $key) {
            return self::$memoryLimit;
        }
        if ('precision' === $key) {
            return self::precisionAsIniString();
        }
        if ('serialize_precision' === $key) {
            return self::serializePrecisionAsIniString();
        }
        if ('unserialize_max_depth' === $key) {
            return self::unserializeMaxDepthAsIniString();
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
        if ('session.use_strict_mode' === $key) {
            return VmSession::isUseStrictMode() ? '1' : '0';
        }
        if ('include_path' === $key) {
            return IncludePathJitHelper::get();
        }
        if ('open_basedir' === $key) {
            return VmOpenBasedir::get();
        }
        if ('default_charset' === $key) {
            return self::$defaultCharset;
        }
        if ('date.timezone' === $key) {
            return VmDate::defaultTimezoneGet();
        }
        if ('user_agent' === $key) {
            return self::$userAgent;
        }
        if ('url_rewriter.tags' === $key) {
            return OutputRewriteVarsJitHelper::getTags();
        }
        if ('url_rewriter.hosts' === $key) {
            return OutputRewriteVarsJitHelper::getHosts();
        }
        if ('pcre.backtrack_limit' === $key) {
            return (string) self::$pcreBacktrackLimit;
        }
        if ('pcre.jit' === $key) {
            return VmIni::formatBoolIniGet(self::$pcreJit);
        }
        if ('pcre.recursion_limit' === $key) {
            return (string) self::$pcreRecursionLimit;
        }
        if ('zend.exception_string_param_max_len' === $key) {
            return (string) self::$exceptionStringParamMaxLen;
        }
        if ('zend.exception_ignore_args' === $key) {
            return VmIni::formatRegisterArgcArgvIniGet(self::$exceptionIgnoreArgs);
        }
        if ('max_execution_time' === $key) {
            return self::$maxExecutionTime;
        }
        if ('register_argc_argv' === $key) {
            return VmIni::formatRegisterArgcArgvIniGet(self::$registerArgcArgv);
        }

        return self::INI_FALSE_SENTINEL;
    }

    /** @return string|null null when ini_set() is false */
    public static function iniSet(string $option, string $newValue)
    {
        $key = strtolower($option);
        if ('max_memory_limit' === $key) {
            return self::INI_FALSE_SENTINEL;
        }
        if (self::rejectSessionIniAfterHeadersSent($key)) {
            return self::INI_FALSE_SENTINEL;
        }
        if (self::isAssertIniKey($key)) {
            return self::assertIniSet($key, $newValue);
        }
        if (!self::isSupportedKey($key)) {
            return self::INI_FALSE_SENTINEL;
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
        if ('precision' === $key) {
            return self::setPrecision($newValue);
        }
        if ('serialize_precision' === $key) {
            return self::setSerializePrecision($newValue);
        }
        if ('unserialize_max_depth' === $key) {
            return self::setUnserializeMaxDepth($newValue);
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
        if ('session.use_strict_mode' === $key) {
            $old = VmSession::isUseStrictMode() ? '1' : '0';
            VmSession::setUseStrictMode(VmIni::parseBoolIni($newValue));

            return $old;
        }
        if ('include_path' === $key) {
            return IncludePathJitHelper::push($newValue);
        }
        if ('open_basedir' === $key) {
            $old = VmOpenBasedir::set($newValue);

            return false === $old ? null : $old;
        }
        if ('default_charset' === $key) {
            return self::setDefaultCharset($newValue);
        }
        if ('date.timezone' === $key) {
            return self::setDateTimezone($newValue);
        }
        if ('user_agent' === $key) {
            return self::setUserAgent($newValue);
        }
        if ('url_rewriter.tags' === $key) {
            $old = OutputRewriteVarsJitHelper::getTags();
            \phpc_rewrite_vars_set_tags_kernel($newValue);
            OutputRewriteVarsJitHelper::setTags($newValue);

            return $old;
        }
        if ('url_rewriter.hosts' === $key) {
            $old = OutputRewriteVarsJitHelper::getHosts();
            OutputRewriteVarsJitHelper::setHosts($newValue);

            return $old;
        }
        if ('pcre.backtrack_limit' === $key) {
            return self::setPcreBacktrackLimit($newValue);
        }
        if ('pcre.jit' === $key) {
            return self::setPcreJit($newValue);
        }
        if ('pcre.recursion_limit' === $key) {
            return self::setPcreRecursionLimit($newValue);
        }
        if ('zend.exception_string_param_max_len' === $key) {
            return self::setExceptionStringParamMaxLen($newValue);
        }
        if ('zend.exception_ignore_args' === $key) {
            return self::setExceptionIgnoreArgs($newValue);
        }
        if ('max_execution_time' === $key) {
            return self::setMaxExecutionTime($newValue);
        }
        if ('register_argc_argv' === $key) {
            return self::INI_FALSE_SENTINEL;
        }

        return self::INI_FALSE_SENTINEL;
    }

    /** @return string|null null when get_cfg_var() is false */
    public static function iniCfgGet(string $option)
    {
        $key = strtolower($option);
        if ('max_memory_limit' === $key) {
            return \PHPCompiler\CompilerVersion::supportsMaxMemoryLimit() ? self::CFG_MAX_MEMORY_LIMIT : self::INI_FALSE_SENTINEL;
        }
        if (self::isCfgEmptyStringKey($key)) {
            return '';
        }
        if (self::isReadonlyBoolKey($key)) {
            return VmIni::formatBoolIniGet(self::readonlyBoolDefault($key));
        }
        if (self::isReadonlyStringKey($key)) {
            return self::readonlyStringDefault($key);
        }
        if ('engine' === $key) {
            return '1';
        }
        if ('zend.exception_ignore_args' === $key) {
            return '1';
        }
        if (self::isAssertIniKey($key)) {
            $value = VmAssertState::iniGet($option);

            return false === $value ? self::INI_FALSE_SENTINEL : $value;
        }
        if (!self::isSupportedKey($key)) {
            return self::INI_FALSE_SENTINEL;
        }

        if ('error_reporting' === $key) {
            return \sprintf('%d', ErrorReporter::defaultStartupReporting());
        }
        if ('display_errors' === $key) {
            return self::CFG_DISPLAY_ERRORS;
        }
        if ('memory_limit' === $key) {
            return self::CFG_MEMORY_LIMIT;
        }
        if ('precision' === $key) {
            return self::CFG_PRECISION;
        }
        if ('serialize_precision' === $key) {
            return self::CFG_SERIALIZE_PRECISION;
        }
        if ('unserialize_max_depth' === $key) {
            return self::CFG_UNSERIALIZE_MAX_DEPTH;
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
        if ('session.use_strict_mode' === $key) {
            return '0';
        }
        if ('max_execution_time' === $key) {
            return self::CFG_MAX_EXECUTION_TIME;
        }
        if ('default_charset' === $key) {
            return self::CFG_DEFAULT_CHARSET;
        }
        if ('cfg_file_path' === $key) {
            $path = VmIniIntrospection::loadedFile();

            return false === $path ? null : $path;
        }
        if ('user_agent' === $key) {
            return '';
        }
        if ('pcre.backtrack_limit' === $key) {
            return self::CFG_PCRE_BACKTRACK_LIMIT;
        }
        if ('pcre.jit' === $key) {
            return '1';
        }
        if ('pcre.recursion_limit' === $key) {
            return self::CFG_PCRE_RECURSION_LIMIT;
        }
        if ('zend.exception_string_param_max_len' === $key) {
            return (string) self::CFG_EXCEPTION_STRING_PARAM_MAX_LEN;
        }
        if ('register_argc_argv' === $key) {
            return VmIni::formatRegisterArgcArgvIniGet(self::$registerArgcArgv);
        }

        return self::INI_FALSE_SENTINEL;
    }

    public static function iniRestore(string $option): void
    {
        $key = strtolower($option);
        if (!self::isSupportedKey($key)) {
            return;
        }

        switch ($key) {
            case 'error_reporting':
                ErrorSilenceJitHelper::iniRestoreErrorReporting();
                break;
            case 'display_errors':
                self::$displayErrorsLocalValue = null;
                self::$displayErrors = VmIni::parseBoolIni(self::CFG_DISPLAY_ERRORS);
                ErrorSilenceJitHelper::setDisplayErrors(self::$displayErrors);
                break;
            case 'memory_limit':
                self::$memoryLimit = VmIni::clampMemoryLimitToMax(self::CFG_MEMORY_LIMIT, null, true);
                VmIni::syncMemoryLimitFromJit(self::$memoryLimit);
                break;
            case 'precision':
                self::$precision = VmIni::parsePrecision(self::CFG_PRECISION);
                break;
            case 'serialize_precision':
                self::$serializePrecision = self::parseSerializePrecisionIni(self::CFG_SERIALIZE_PRECISION);
                break;
            case 'unserialize_max_depth':
                self::$unserializeMaxDepth = (int) self::CFG_UNSERIALIZE_MAX_DEPTH;
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
            case 'session.use_strict_mode':
                VmSession::setUseStrictMode(false);
                break;
            case 'open_basedir':
                VmOpenBasedir::restore();
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
            case 'zend.exception_string_param_max_len':
                self::$exceptionStringParamMaxLen = self::CFG_EXCEPTION_STRING_PARAM_MAX_LEN;
                VmIni::syncExceptionStringParamMaxLen(self::$exceptionStringParamMaxLen);
                break;
            case 'zend.exception_ignore_args':
                self::$exceptionIgnoreArgs = self::CFG_EXCEPTION_IGNORE_ARGS;
                VmIni::syncExceptionIgnoreArgs(self::$exceptionIgnoreArgs);
                break;
            case 'max_execution_time':
                self::$maxExecutionTime = self::CFG_MAX_EXECUTION_TIME;
                ExecutionLimitsJitHelper::applyMaxExecutionTime((int) self::CFG_MAX_EXECUTION_TIME);
                break;
            case 'register_argc_argv':
                self::$registerArgcArgv = true;
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
        $old = self::displayErrorsIniString();
        self::$displayErrorsLocalValue = $newValue;
        self::$displayErrors = VmIni::parseBoolIni($newValue);
        ErrorSilenceJitHelper::setDisplayErrors(self::$displayErrors);

        return $old;
    }

    private static function displayErrorsIniString(): string
    {
        if (null !== self::$displayErrorsLocalValue) {
            return self::$displayErrorsLocalValue;
        }

        return VmIni::formatBoolIniGet(self::$displayErrors);
    }

    private static function setMemoryLimit(string $newValue): string
    {
        $old = self::$memoryLimit;
        $effective = VmIni::clampMemoryLimitToMax($newValue, null, true);
        self::$memoryLimit = $effective;
        VmIni::syncMemoryLimitFromJit($effective);

        return $old;
    }

    private static function setPrecision(string $newValue): string
    {
        $old = self::precisionAsIniString();
        self::$precision = VmIni::parsePrecision($newValue);
        VmIni::syncPrecision(self::$precision);

        return $old;
    }

    private static function setSerializePrecision(string $newValue): string
    {
        $old = self::serializePrecisionAsIniString();
        self::$serializePrecision = self::parseSerializePrecisionIni($newValue);

        return $old;
    }

    /** @return string|null null when ini_set rejected the value */
    private static function setUnserializeMaxDepth(string $newValue): ?string
    {
        $parsed = (int) trim($newValue);
        if ($parsed <= 0) {
            return self::INI_FALSE_SENTINEL;
        }
        $old = self::unserializeMaxDepthAsIniString();
        self::$unserializeMaxDepth = $parsed;

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
            return self::INI_FALSE_SENTINEL;
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

    /** @return string|null null when timezone id is invalid */
    private static function setDateTimezone(string $newValue): ?string
    {
        $old = VmDate::defaultTimezoneGet();
        if (!VmDate::tryDefaultTimezoneSet($newValue)) {
            return self::INI_FALSE_SENTINEL;
        }

        return $old;
    }

    private static function setPcreBacktrackLimit(string $newValue): ?string
    {
        $parsed = (int) $newValue;
        if ($parsed < 0) {
            return self::INI_FALSE_SENTINEL;
        }
        $old = (string) self::$pcreBacktrackLimit;
        self::$pcreBacktrackLimit = $parsed;

        return $old;
    }

    private static function setPcreJit(string $newValue): string
    {
        $old = VmIni::formatBoolIniGet(self::$pcreJit);
        self::$pcreJit = VmIni::parseBoolIni($newValue);

        return $old;
    }

    private static function setPcreRecursionLimit(string $newValue): ?string
    {
        $parsed = (int) $newValue;
        if ($parsed < 0) {
            return self::INI_FALSE_SENTINEL;
        }
        $old = (string) self::$pcreRecursionLimit;
        self::$pcreRecursionLimit = $parsed;

        return $old;
    }

    private static function setExceptionStringParamMaxLen(string $newValue): ?string
    {
        $parsed = (int) trim($newValue);
        if ($parsed < 0 || $parsed > self::EXCEPTION_STRING_PARAM_MAX_LEN_CEILING) {
            return self::INI_FALSE_SENTINEL;
        }
        $old = (string) self::$exceptionStringParamMaxLen;
        self::$exceptionStringParamMaxLen = $parsed;
        VmIni::syncExceptionStringParamMaxLen($parsed);

        return $old;
    }

    private static function setExceptionIgnoreArgs(string $newValue): string
    {
        $old = VmIni::formatRegisterArgcArgvIniGet(self::$exceptionIgnoreArgs);
        self::$exceptionIgnoreArgs = VmIni::parseBoolIni($newValue);
        VmIni::syncExceptionIgnoreArgs(self::$exceptionIgnoreArgs);

        return $old;
    }

    private static function setMaxExecutionTime(string $newValue): string
    {
        $parsed = (int) trim($newValue);
        $old = self::$maxExecutionTime;
        ExecutionLimitsJitHelper::applyMaxExecutionTime($parsed);

        return $old;
    }

    /** @return string|null */
    private static function assertIniGet(string $key)
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

        return self::INI_FALSE_SENTINEL;
    }

    /** @return string|null */
    private static function assertIniSet(string $key, string $newValue)
    {
        if ('zend.assertions' === $key) {
            $result = AssertOptionsJitHelper::iniSetZendAssertionsFromString($newValue);
            if (null === $result) {
                TriggerErrorJitHelper::warning(AssertOptionsJitHelper::MSG_ZEND_ASSERTIONS_PHP_INI_ONLY);
            }

            return $result;
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

        return self::INI_FALSE_SENTINEL;
    }

    /** php-src ext/session/session.c — session ini cannot change after headers sent (#11548). */
    private static function rejectSessionIniAfterHeadersSent(string $key): bool
    {
        if (!SapiOutput::headersSent()) {
            return false;
        }

        return 'session.save_path' === $key
            || 'session.gc_maxlifetime' === $key
            || 'session.use_strict_mode' === $key;
    }

    private static function isReadonlyBoolKey(string $key): bool
    {
        return 'enable_dl' === $key
            || 'short_open_tag' === $key
            || 'zend.enable_gc' === $key
            || 'session.use_cookies' === $key
            || 'session.use_only_cookies' === $key
            || 'allow_url_fopen' === $key
            || 'allow_url_include' === $key;
    }

    private static function isReadonlyStringKey(string $key): bool
    {
        return 'session.save_handler' === $key
            || 'user_ini.filename' === $key
            || 'realpath_cache_size' === $key
            || 'realpath_cache_ttl' === $key
            || 'post_max_size' === $key
            || 'upload_max_filesize' === $key
            || 'default_socket_timeout' === $key
            || 'auto_detect_line_endings' === $key
            || 'default_mimetype' === $key
            || 'variables_order' === $key
            || 'request_order' === $key
            || 'arg_separator.output' === $key;
    }

    /** Caller must check {@see isReadonlyBoolKey} first. */
    private static function readonlyBoolDefault(string $key): bool
    {
        return match ($key) {
            'enable_dl', 'short_open_tag', 'allow_url_include' => false,
            default => true,
        };
    }

    /** Caller must check {@see isReadonlyStringKey} first. */
    private static function readonlyStringDefault(string $key): string
    {
        return match ($key) {
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
            default => '',
        };
    }

    private static function isAssertIniKey(string $key): bool
    {
        return 'zend.assertions' === $key
            || 'assert.active' === $key
            || 'assert.bail' === $key
            || 'assert.callback' === $key
            || 'assert.exception' === $key;
    }

    private static function isEmptyStringIniKey(string $key): bool
    {
        return 'auto_prepend_file' === $key
            || 'auto_append_file' === $key
            || 'browscap' === $key
            || 'error_log' === $key
            || 'doc_root' === $key
            || 'user_dir' === $key
            || 'disable_functions' === $key
            || 'disable_classes' === $key
            || 'mail.add_x_header' === $key
            || 'error_append_string' === $key
            || 'error_prepend_string' === $key
            || 'upload_tmp_dir' === $key
            || 'sys_temp_dir' === $key;
    }

    private static function isCfgEmptyStringKey(string $key): bool
    {
        return 'auto_prepend_file' === $key
            || 'auto_append_file' === $key
            || 'doc_root' === $key
            || 'user_dir' === $key
            || 'disable_functions' === $key
            || 'disable_classes' === $key
            || 'mail.add_x_header' === $key;
    }

    private static function isMirroredHostIniKey(string $key): bool
    {
        return 'extension_dir' === $key
            || 'sendmail_path' === $key
            || 'mail.force_extra_parameters' === $key;
    }

    private static function isSupportedKey(string $key): bool
    {
        return 'error_reporting' === $key
            || 'display_errors' === $key
            || 'memory_limit' === $key
            || 'precision' === $key
            || 'serialize_precision' === $key
            || 'unserialize_max_depth' === $key
            || 'unserialize_callback_func' === $key
            || 'session.gc_maxlifetime' === $key
            || 'session.save_path' === $key
            || 'session.use_strict_mode' === $key
            || 'include_path' === $key
            || 'open_basedir' === $key
            || 'short_open_tag' === $key
            || 'register_argc_argv' === $key
            || 'zend.enable_gc' === $key
            || 'max_execution_time' === $key
            || 'default_charset' === $key
            || 'date.timezone' === $key
            || 'cfg_file_path' === $key
            || 'user_agent' === $key
            || 'url_rewriter.tags' === $key
            || 'url_rewriter.hosts' === $key
            || 'pcre.backtrack_limit' === $key
            || 'pcre.jit' === $key
            || 'pcre.recursion_limit' === $key
            || 'zend.exception_string_param_max_len' === $key
            || 'zend.exception_ignore_args' === $key
            || 'zend.assertions' === $key
            || 'assert.active' === $key
            || 'assert.bail' === $key
            || 'assert.callback' === $key
            || 'assert.exception' === $key;
    }
}
