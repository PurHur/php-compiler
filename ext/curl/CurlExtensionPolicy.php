<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\CompilerVersion;

/**
 * ext/curl advertisement — php-src ext/curl/interface.c (#12117, #13588, #16659, #3325, #23953).
 *
 * Zend never splits CURLFile / CURLStringFile / CurlShareHandle from the module —
 * withhold class_exists / function_exists / extension_loaded until the host Zend
 * build loads ext/curl (or {@code PHP_COMPILER_ENABLE_CURL=1} for functional PHPT).
 * Do **not** advertise solely because libcurl FFI is present (#23953, same host-module
 * gate as dba #24134 / mailparse #24908).
 *
 * In-tree PHP implementations stay compiled for intentional enable / host-loaded builds.
 */
final class CurlExtensionPolicy
{
    /**
     * extension_loaded('curl') / CREDITS_MODULES — host Zend module or explicit enable (#23953).
     */
    public static function advertisesExtension(): bool
    {
        if (\extension_loaded('curl')) {
            return true;
        }

        return self::explicitEnableRequested();
    }

    public static function advertisesBuiltins(): bool
    {
        return self::advertisesExtension();
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
        if (self::isCurlPhantomComplianceCase($testFileName)
            && (str_contains($testFileName, 'curl_file_phantom')
                || str_contains($testFileName, 'curl_string_file_phantom'))) {
            return !self::advertisesFileClasses();
        }

        // Functional cases set PHP_COMPILER_ENABLE_CURL via --ENV-- (#23953).
        return true;
    }

    /**
     * curl_share_* + CurlShareHandle — only with loaded ext/curl (#19728, re-#12117).
     *
     * Zend registers share APIs in the same module startup as curl_init (php-src
     * ext/curl/interface.c / curl.stub.php). Advertising share while withholding
     * easy-handle entrypoints is a phantom split; gate on {@see advertisesExtension()}
     * so share + core surfaces appear together when curl is enabled.
     */
    public static function advertisesShareHandles(): bool
    {
        return self::advertisesExtension();
    }

    /** Run curl_share_* compliance when share is advertised or a phantom guard matches (#19728). */
    public static function runsCurlShareCompliance(string $testFileName): bool
    {
        if (self::isCurlPhantomComplianceCase($testFileName)
            && (str_contains($testFileName, 'curl_share_phantom')
                || str_contains($testFileName, 'curl_share_persistent_phantom'))) {
            return !self::advertisesShareHandles();
        }

        return true;
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
        if (str_contains($testFileName, 'curl_share_persistent_phantom')) {
            return !self::advertisesSharePersistentHandles();
        }

        return true;
    }

    /** Run curl_multi_* compliance when multi is advertised or a phantom guard matches (#3721). */
    public static function runsCurlMultiCompliance(string $testFileName): bool
    {
        if (str_contains($testFileName, 'curl_multi_phantom')) {
            return !self::advertisesMultiHandles();
        }

        return true;
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
        if (str_contains($testFileName, 'curl_easy_phantom')) {
            return !self::advertisesEasyHandleStubs();
        }

        return true;
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

    /** Compliance filenames that exercise curl_* / Curl* / extension_loaded('curl'). */
    public static function isCurlComplianceCase(string $testFileName): bool
    {
        if (str_contains($testFileName, 'curly_brace')) {
            return false;
        }

        return str_contains($testFileName, 'curl_')
            || str_contains($testFileName, 'extension_loaded_curl')
            || str_contains($testFileName, 'curlfile')
            || str_contains($testFileName, 'curlstringfile');
    }

    /** Phantom-registration guards that assert curl is withheld (#23953). */
    public static function isCurlPhantomComplianceCase(string $testFileName): bool
    {
        return str_contains($testFileName, 'curl_escape_phantom')
            || str_contains($testFileName, 'curl_file_phantom')
            || str_contains($testFileName, 'curl_string_file_phantom')
            || str_contains($testFileName, 'curl_share_phantom')
            || str_contains($testFileName, 'curl_share_persistent_phantom')
            || str_contains($testFileName, 'curl_multi_phantom')
            || str_contains($testFileName, 'curl_easy_phantom')
            || str_contains($testFileName, 'extension_loaded_curl_phantom')
            || str_contains($testFileName, 'extension_loaded_curl_openssl')
            || str_contains($testFileName, 'maintainer_gap_curl_extension_phantom');
    }

    /**
     * Functional curl cases set {@code PHP_COMPILER_ENABLE_CURL} via {@code --ENV--};
     * module phantom guards run only when curl is withheld (#23953).
     *
     * Note: {@code curl_version_feature_list_phantom_82} / {@code curl_version_phantom_function_exists}
     * are profile/positive guards (not extension-withhold phantoms) and still need ENABLE when the
     * host lacks ext/curl.
     */
    public static function runsCurlCompliance(string $testFileName): bool
    {
        if (self::isCurlPhantomComplianceCase($testFileName)) {
            return !self::advertisesExtension();
        }

        return true;
    }

    /** Explicit side-load / functional-test opt-in when host Zend lacks ext/curl (#23953). */
    private static function explicitEnableRequested(): bool
    {
        $raw = getenv('PHP_COMPILER_ENABLE_CURL');
        if (!\is_string($raw) || '' === trim($raw)) {
            return false;
        }

        $v = strtolower(trim($raw));

        return !\in_array($v, ['0', 'false', 'off', 'no'], true);
    }
}
