<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\CompilerVersion;

/**
 * ext/curl advertisement — php-src ext/curl/interface.c (#12117, #13588, #16659, #3325).
 *
 * Zend never splits CURLFile / CURLStringFile / CurlShareHandle from the module —
 * withhold class_exists / function_exists until {@see advertisesExtension()} when
 * libcurl FFI is available (#19671, #19728, same pattern as #19670).
 */
final class CurlExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return VmCurlCore::available();
    }

    /**
     * extension_loaded('curl') / CREDITS_MODULES — true once libcurl easy I/O ships (#11627, #16748, #3325).
     */
    public static function advertisesExtension(): bool
    {
        return VmCurlNative::available();
    }

    public static function advertisesHandleClasses(): bool
    {
        return self::advertisesExtension();
    }

    /**
     * CURLFile / CURLStringFile / curl_file_create — require loaded ext/curl
     * (php-src ext/curl/curl_file.c; #19671, re-#6790/#6918).
     *
     * Implementations stay in-tree for when curl loads; no phantom class_exists when
     * extension_loaded('curl') is false.
     */
    public static function advertisesFileClasses(): bool
    {
        return self::advertisesExtension();
    }

    /** Run CURLFile / CURLStringFile compliance when curl is loaded or a phantom guard matches (#19671). */
    public static function runsCurlFileCompliance(string $testFileName): bool
    {
        if (self::advertisesFileClasses()) {
            return true;
        }

        return str_contains($testFileName, 'curl_file_phantom')
            || str_contains($testFileName, 'curl_string_file_phantom');
    }

    /**
     * curl_share_* + CurlShareHandle — only with loaded ext/curl (#19728, re-#12117).
     *
     * Zend registers share APIs in the same module startup as curl_init (php-src
     * ext/curl/interface.c / curl.stub.php). Advertising share while withholding
     * easy-handle entrypoints is a phantom split; gate on {@see advertisesExtension()}
     * so share + core surfaces appear together when #3325 lands.
     */
    public static function advertisesShareHandles(): bool
    {
        return self::advertisesExtension();
    }

    /** Run curl_share_* compliance when share is advertised or a phantom guard matches (#19728). */
    public static function runsCurlShareCompliance(string $testFileName): bool
    {
        if (self::advertisesShareHandles()) {
            return true;
        }

        return str_contains($testFileName, 'curl_share_phantom')
            || str_contains($testFileName, 'curl_share_persistent_phantom')
            || str_contains($testFileName, 'class_exists_curlhandle_no_curl');
    }

    /**
     * curl_multi_* + CurlMultiHandle — with loaded ext/curl (#3721, same gate as easy).
     */
    public static function advertisesMultiHandles(): bool
    {
        return self::advertisesExtension();
    }

    /**
     * PHP 8.4+ CURLOPT/CURLINFO constants (php-src curl.stub.php; #21336, #22837).
     *
     * Withholds TCP_KEEPCNT / PREREQFUNCTION / SERVER_RESPONSE_TIMEOUT / DEBUGFUNCTION /
     * POSTTRANSFER_TIME_T / HTTP_VERSION_3 on the 8.2 reference profile.
     */
    public static function advertisesPhp84OptionConstants(): bool
    {
        return self::advertisesExtension()
            && CompilerVersion::advertisesPhp84CurlOptionConstants();
    }

    /**
     * curl_multi_get_handles() — PHP 8.5+ only (php-src ext/curl/multi.c; #20520).
     *
     * Withheld on 8.4 profiles so function_exists matches Zend 8.4 (no phantom 8.5 symbol).
     */
    public static function advertisesMultiGetHandles(): bool
    {
        return self::advertisesMultiHandles()
            && CompilerVersion::advertisesCurlMultiGetHandles();
    }

    /**
     * curl_share_init_persistent() + CurlSharePersistentHandle — PHP 8.5+ (php-src share.c; #20530).
     *
     * Withheld on 8.4 profiles so function_exists/class_exists match Zend 8.4.
     */
    public static function advertisesSharePersistentHandles(): bool
    {
        return self::advertisesShareHandles()
            && CompilerVersion::advertisesCurlShareInitPersistent();
    }

    /** Run persistent-share compliance when advertised or a phantom/profile guard matches (#20530). */
    public static function runsCurlSharePersistentCompliance(string $testFileName): bool
    {
        if (self::advertisesSharePersistentHandles()) {
            return true;
        }

        return str_contains($testFileName, 'curl_share_persistent_phantom')
            || str_contains($testFileName, 'curl_share_init_persistent');
    }

    /** Run curl_multi_* compliance when multi is advertised or a phantom guard matches (#3721). */
    public static function runsCurlMultiCompliance(string $testFileName): bool
    {
        if (self::advertisesMultiHandles()) {
            return true;
        }

        return str_contains($testFileName, 'curl_multi_phantom');
    }

    /**
     * curl_init/curl_setopt/curl_close — only when ext/curl is loaded (#18470, #11627).
     *
     * Share + easy-handle entrypoints must not appear in function_exists until
     * extension_loaded('curl') is true (Zend parity; #19728).
     */
    public static function advertisesEasyHandleStubs(): bool
    {
        return self::advertisesExtension();
    }

    /** Run curl_init/curl_setopt_array compliance when easy handles ship or phantom guard matches (#6695). */
    public static function runsCurlEasyCompliance(string $testFileName): bool
    {
        if (self::advertisesEasyHandleStubs()) {
            return true;
        }

        return str_contains($testFileName, 'curl_easy_phantom');
    }

    /**
     * curl_version/curl_strerror/… — function_exists only when ext/curl is loaded (#18554, #18470).
     *
     * Phase-2 stubs may stay registered for direct calls; introspection matches Zend ext/curl/interface.c.
     */
    public static function advertisesIntrospectionFunctions(): bool
    {
        return self::advertisesExtension();
    }
}
