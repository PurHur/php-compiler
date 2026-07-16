<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * ext/curl advertisement — php-src ext/curl/interface.c (#12117, #13588, #16659).
 *
 * Phase 2 introspection ({@see VmCurlCore}) keeps curl_* implementations in-tree without
 * libcurl HTTP I/O (#3325). Zend never splits CURLFile / CURLStringFile / CurlShareHandle
 * from the module — withhold class_exists / function_exists until {@see advertisesExtension()}
 * (#19671, #19728, same pattern as #19670).
 */
final class CurlExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return VmCurlCore::available();
    }

    /**
     * extension_loaded('curl') / CREDITS_MODULES — false until curl_init() ships (#11627, #16748, #3325).
     */
    public static function advertisesExtension(): bool
    {
        return false;
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
            || str_contains($testFileName, 'class_exists_curlhandle_no_curl');
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
