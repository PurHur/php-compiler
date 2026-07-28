<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * ext/standard module bootstrap constants (php-src ext/standard/basic_functions.c PHP_MINIT).
 *
 * Single source for Module::init() registration and get_defined_constants(true) buckets (#17416).
 */
final class StdlibModuleConstants
{
    /** Names Zend places in Core when registered via defineConstant (basic_functions.c). */
    public const CORE_BUCKET_NAMES = [
        'DEBUG_BACKTRACE_PROVIDE_OBJECT',
        'DEBUG_BACKTRACE_IGNORE_ARGS',
    ];

    /**
     * Integer constants registered at ext/standard module init.
     *
     * @return array<string, int>
     */
    public static function bootstrapIntConstants(): array
    {
        return [
            'LOCK_SH' => 1,
            'LOCK_EX' => 2,
            'LOCK_UN' => 3,
            'LOCK_NB' => 4,
            'SEEK_SET' => StdlibConstants::SEEK_SET,
            'SEEK_CUR' => StdlibConstants::SEEK_CUR,
            'SEEK_END' => StdlibConstants::SEEK_END,
            'DEBUG_BACKTRACE_PROVIDE_OBJECT' => VmDebugBacktrace::PROVIDE_OBJECT,
            'DEBUG_BACKTRACE_IGNORE_ARGS' => VmDebugBacktrace::IGNORE_ARGS,
            'DEBUG_BACKTRACE_IGNORE_STATIC_ARGS' => VmDebugBacktrace::IGNORE_STATIC_ARGS,
            'CONNECTION_NORMAL' => VmConnection::NORMAL,
            'CONNECTION_ABORTED' => VmConnection::ABORTED,
            'CONNECTION_TIMEOUT' => VmConnection::TIMEOUT,
            'INFO_GENERAL' => VmInfo::INFO_GENERAL,
            'INFO_CREDITS' => VmInfo::INFO_CREDITS,
            'INFO_CONFIGURATION' => VmInfo::INFO_CONFIGURATION,
            'INFO_MODULES' => VmInfo::INFO_MODULES,
            'INFO_ENVIRONMENT' => VmInfo::INFO_ENVIRONMENT,
            'INFO_VARIABLES' => VmInfo::INFO_VARIABLES,
            'INFO_LICENSE' => VmInfo::INFO_LICENSE,
            'INFO_ALL' => VmInfo::INFO_ALL,
            'CREDITS_GROUP' => VmInfo::CREDITS_GROUP,
            'CREDITS_GENERAL' => VmInfo::CREDITS_GENERAL,
            'CREDITS_SAPI' => VmInfo::CREDITS_SAPI,
            'CREDITS_MODULES' => VmInfo::CREDITS_MODULES,
            'CREDITS_DOCS' => VmInfo::CREDITS_DOCS,
            'CREDITS_FULLPAGE' => VmInfo::CREDITS_FULLPAGE,
            'CREDITS_QA' => VmInfo::CREDITS_QA,
            'CREDITS_WEB' => VmInfo::CREDITS_WEB,
            'CREDITS_ALL' => VmInfo::CREDITS_ALL,
            'PHP_QUERY_RFC1738' => VmHttpBuildQuery::ENCODING_RFC1738,
            'PHP_QUERY_RFC3986' => VmHttpBuildQuery::ENCODING_RFC3986,
            'PHP_URL_SCHEME' => VmParseUrl::PHP_URL_SCHEME,
            'PHP_URL_HOST' => VmParseUrl::PHP_URL_HOST,
            'PHP_URL_PORT' => VmParseUrl::PHP_URL_PORT,
            'PHP_URL_USER' => VmParseUrl::PHP_URL_USER,
            'PHP_URL_PASS' => VmParseUrl::PHP_URL_PASS,
            'PHP_URL_PATH' => VmParseUrl::PHP_URL_PATH,
            'PHP_URL_QUERY' => VmParseUrl::PHP_URL_QUERY,
            'PHP_URL_FRAGMENT' => VmParseUrl::PHP_URL_FRAGMENT,
            'SUNFUNCS_RET_STRING' => VmDate::SUNFUNCS_RET_STRING,
            'SUNFUNCS_RET_DOUBLE' => VmDate::SUNFUNCS_RET_DOUBLE,
            'SUNFUNCS_RET_TIMESTAMP' => VmDate::SUNFUNCS_RET_TIMESTAMP,
            'LOG_EMERG' => StdlibConstants::LOG_EMERG,
            'LOG_ALERT' => StdlibConstants::LOG_ALERT,
            'LOG_CRIT' => StdlibConstants::LOG_CRIT,
            'LOG_ERR' => StdlibConstants::LOG_ERR,
            'LOG_WARNING' => StdlibConstants::LOG_WARNING,
            'LOG_NOTICE' => StdlibConstants::LOG_NOTICE,
            'LOG_INFO' => StdlibConstants::LOG_INFO,
            'LOG_DEBUG' => StdlibConstants::LOG_DEBUG,
            'LOG_PID' => StdlibConstants::LOG_PID,
            'LOG_CONS' => StdlibConstants::LOG_CONS,
            'LOG_ODELAY' => StdlibConstants::LOG_ODELAY,
            'LOG_NDELAY' => StdlibConstants::LOG_NDELAY,
            'LOG_NOWAIT' => StdlibConstants::LOG_NOWAIT,
            'LOG_PERROR' => StdlibConstants::LOG_PERROR,
            'LOG_KERN' => StdlibConstants::LOG_KERN,
            'LOG_USER' => StdlibConstants::LOG_USER,
            'LOG_MAIL' => StdlibConstants::LOG_MAIL,
            'LOG_DAEMON' => StdlibConstants::LOG_DAEMON,
            'LOG_AUTH' => StdlibConstants::LOG_AUTH,
            'LOG_SYSLOG' => StdlibConstants::LOG_SYSLOG,
            'LOG_LPR' => StdlibConstants::LOG_LPR,
            'LOG_NEWS' => StdlibConstants::LOG_NEWS,
            'LOG_UUCP' => StdlibConstants::LOG_UUCP,
            'LOG_CRON' => StdlibConstants::LOG_CRON,
            'LOG_AUTHPRIV' => StdlibConstants::LOG_AUTHPRIV,
            'LOG_FTP' => StdlibConstants::LOG_FTP,
            'LOG_LOCAL0' => StdlibConstants::LOG_LOCAL0,
            'LOG_LOCAL1' => StdlibConstants::LOG_LOCAL1,
            'LOG_LOCAL2' => StdlibConstants::LOG_LOCAL2,
            'LOG_LOCAL3' => StdlibConstants::LOG_LOCAL3,
            'LOG_LOCAL4' => StdlibConstants::LOG_LOCAL4,
            'LOG_LOCAL5' => StdlibConstants::LOG_LOCAL5,
            'LOG_LOCAL6' => StdlibConstants::LOG_LOCAL6,
            'LOG_LOCAL7' => StdlibConstants::LOG_LOCAL7,
            ...VmLocale::lcConstants(),
            ...VmLocale::nlLanginfoConstants(),
            'ZLIB_ENCODING_RAW' => -15,
            'ZLIB_ENCODING_DEFLATE' => 15,
            'ZLIB_ENCODING_GZIP' => 31,
            'ZLIB_NO_FLUSH' => 0,
            'ZLIB_PARTIAL_FLUSH' => 1,
            'ZLIB_SYNC_FLUSH' => 2,
            'ZLIB_FULL_FLUSH' => 3,
            'ZLIB_FINISH' => 4,
            'ZLIB_BLOCK' => 5,
            // Status codes — zlib.h / php-src ext/zlib/zlib.stub.php (#24109)
            'ZLIB_OK' => 0,
            'ZLIB_STREAM_END' => 1,
            'ZLIB_NEED_DICT' => 2,
            'ZLIB_ERRNO' => -1,
            'ZLIB_STREAM_ERROR' => -2,
            'ZLIB_DATA_ERROR' => -3,
            'ZLIB_MEM_ERROR' => -4,
            'ZLIB_BUF_ERROR' => -5,
            'ZLIB_VERSION_ERROR' => -6,
            // Compression strategy — zlib.h / php-src (#24109)
            'ZLIB_FILTERED' => 1,
            'ZLIB_HUFFMAN_ONLY' => 2,
            'ZLIB_RLE' => 3,
            'ZLIB_FIXED' => 4,
            'ZLIB_DEFAULT_STRATEGY' => 0,
            ...self::zlibVernumConstant(),
            // Legacy encoding aliases — php-src ext/zlib/zlib.c (#24052)
            'FORCE_GZIP' => 31,
            'FORCE_DEFLATE' => 15,
            'STREAM_PF_UNIX' => StdlibConstants::STREAM_PF_UNIX,
            'STREAM_PF_INET' => StdlibConstants::STREAM_PF_INET,
            'STREAM_SOCK_STREAM' => StdlibConstants::STREAM_SOCK_STREAM,
            'STREAM_SOCK_DGRAM' => StdlibConstants::STREAM_SOCK_DGRAM,
            'STREAM_IPPROTO_IP' => StdlibConstants::STREAM_IPPROTO_IP,
            'STREAM_CLIENT_PERSISTENT' => StdlibConstants::STREAM_CLIENT_PERSISTENT,
            'STREAM_CLIENT_ASYNC_CONNECT' => StdlibConstants::STREAM_CLIENT_ASYNC_CONNECT,
            'STREAM_CLIENT_CONNECT' => StdlibConstants::STREAM_CLIENT_CONNECT,
            'STREAM_REPORT_ERRORS' => StdlibConstants::STREAM_REPORT_ERRORS,
            'STREAM_CAST_AS_STREAM' => StdlibConstants::STREAM_CAST_AS_STREAM,
            'STREAM_CAST_FOR_SELECT' => StdlibConstants::STREAM_CAST_FOR_SELECT,
            'PSFS_PASS_ON' => StdlibConstants::PSFS_PASS_ON,
            'PSFS_FEED_ME' => StdlibConstants::PSFS_FEED_ME,
            'PSFS_FLAG_NORMAL' => StdlibConstants::PSFS_FLAG_NORMAL,
            'PSFS_FLAG_FLUSH_INC' => StdlibConstants::PSFS_FLAG_FLUSH_INC,
            'PSFS_FLAG_FLUSH_CLOSE' => StdlibConstants::PSFS_FLAG_FLUSH_CLOSE,
        ] + VmStreamSupports::constants() + VmStreamNotification::constants() + VmImage::constants() + VmJsonFlags::constants()
            + VmHttpConstants::constants() + GetDefinedConstantsParity::standardIntConstants();
    }

    /**
     * String constants from ext/standard bootstrap (DATE_* + html entity table names).
     *
     * @return array<string, string>
     */
    public static function bootstrapStringConstants(): array
    {
        $out = DateConstants::registeredConstants();
        foreach (StdlibConstants::CORE_STRING_BY_NAME as $lc => $value) {
            $out[strtoupper($lc)] = $value;
        }

        return $out + self::zlibVersionConstant() + GetDefinedConstantsParity::standardStringConstants();
    }

    /**
     * Pinned zlib identity for php-compiler:22.04-dev / Ubuntu 22.04 (zlib 1.2.11).
     * Used when host Zend does not expose ZLIB_VERSION / ZLIB_VERNUM (AOT/self-host).
     */
    public const ZLIB_VERSION_FALLBACK = '1.2.11';

    public const ZLIB_VERNUM_FALLBACK = 4784;

    /**
     * ZLIB_VERNUM — php-src ext/zlib/zlib.c (#24072). Prefer host Zend when present.
     *
     * @return array<string, int>
     */
    public static function zlibVernumConstant(): array
    {
        if (\defined('ZLIB_VERNUM')) {
            return ['ZLIB_VERNUM' => (int) \constant('ZLIB_VERNUM')];
        }

        return ['ZLIB_VERNUM' => self::ZLIB_VERNUM_FALLBACK];
    }

    /**
     * ZLIB_VERSION — php-src ext/zlib/zlib.c (#24072). Prefer host Zend when present.
     *
     * @return array<string, string>
     */
    public static function zlibVersionConstant(): array
    {
        if (\defined('ZLIB_VERSION')) {
            return ['ZLIB_VERSION' => (string) \constant('ZLIB_VERSION')];
        }

        return ['ZLIB_VERSION' => self::ZLIB_VERSION_FALLBACK];
    }

    /**
     * @return array<string, float>
     */
    public static function bootstrapFloatConstants(): array
    {
        $out = [];
        foreach (StdlibConstants::CORE_FLOAT_BY_NAME as $lc => $value) {
            $out[strtoupper($lc)] = $value;
        }

        return $out + GetDefinedConstantsParity::standardFloatConstants();
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function categorizedBootstrapConstants(): array
    {
        $standard = self::standardModuleConstants();
        $zlib = [];
        $json = [];
        foreach ($standard as $name => $value) {
            if (GetDefinedConstantsParity::isStandardBucketExcluded($name)) {
                unset($standard[$name]);
                continue;
            }
            if (str_starts_with($name, 'ZLIB_') || str_starts_with($name, 'FORCE_')) {
                $zlib[$name] = $value;
                unset($standard[$name]);
            } elseif (str_starts_with($name, 'JSON_')) {
                $json[$name] = $value;
                unset($standard[$name]);
            } elseif (str_starts_with($name, 'PREG_') || str_starts_with($name, 'PCRE_')) {
                unset($standard[$name]);
            }
        }

        $date = DateConstants::registeredConstants() + DateConstants::sunfuncsConstants();
        foreach (GetDefinedConstantsParity::sunfuncsConstantNames() as $name) {
            if (isset($standard[$name])) {
                unset($standard[$name]);
            }
        }

        return [
            'standard' => $standard,
            'zlib' => $zlib,
            'json' => $json + GetDefinedConstantsParity::jsonIntConstants(),
            'pcre' => PcreConstants::registeredConstants(),
            'date' => $date,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function standardModuleConstants(): array
    {
        $standard = [];
        foreach (self::bootstrapIntConstants() as $name => $value) {
            if (\in_array($name, self::CORE_BUCKET_NAMES, true)) {
                continue;
            }
            $standard[$name] = $value;
        }
        foreach (self::bootstrapStringConstants() as $name => $value) {
            if (str_starts_with($name, 'DATE_')) {
                continue;
            }
            $standard[$name] = $value;
        }
        foreach (StdlibConstants::CORE_INT_BY_NAME as $lc => $value) {
            if (null === StdlibConstants::coreIntByName($lc)) {
                continue;
            }
            $standard[strtoupper($lc)] = $value;
        }
        foreach (StdlibConstants::CORE_FLOAT_BY_NAME as $lc => $value) {
            $standard[strtoupper($lc)] = $value;
        }
        foreach (GetDefinedConstantsParity::standardFloatConstants() as $name => $value) {
            $standard[$name] = $value;
        }
        foreach (StdlibConstants::CORE_STRING_BY_NAME as $lc => $value) {
            $standard[strtoupper($lc)] = $value;
        }
        $standard['DIRECTORY_SEPARATOR'] = VmPhpCoreConstants::directorySeparatorValue();
        $standard['PATH_SEPARATOR'] = VmPhpCoreConstants::pathSeparatorValue();

        return $standard;
    }
}
