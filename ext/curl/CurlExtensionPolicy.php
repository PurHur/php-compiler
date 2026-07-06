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

    public static function advertisesHandleClasses(): bool
    {
        return self::advertisesBuiltins();
    }
}
