<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ext\standard\ModuleRegistry;

/**
 * ext/curl advertisement — php-src ext/curl/interface.c (#12117, #13588).
 *
 * Handle CEs and curl_escape/curl_unescape register only when
 * {@see ModuleRegistry::extensionLoaded}('curl') is true (libcurl client #3325).
 * CURLFile may still register under standard.
 */
final class CurlExtensionPolicy
{
    public static function advertisesBuiltins(): bool
    {
        return ModuleRegistry::extensionLoaded('curl');
    }

    public static function advertisesHandleClasses(): bool
    {
        return self::advertisesBuiltins();
    }
}
