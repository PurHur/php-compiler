<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

/**
 * ext/curl advertisement — php-src ext/curl/interface.c (#12117, #13588, #16659).
 *
 * Phase 2 introspection ({@see VmCurlCore}) registers curl_version/curl_strerror and
 * CURLStringFile without libcurl HTTP I/O (#3325). Handle CEs register with the same gate.
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
}
