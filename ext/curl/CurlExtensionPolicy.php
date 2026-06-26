<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\ext\standard\ModuleRegistry;

/**
 * ext/curl handle class advertisement — php-src ext/curl/interface.c (#12117).
 *
 * CurlHandle/CurlMultiHandle/CurlShareHandle CEs register only when
 * {@see ModuleRegistry::extensionLoaded}('curl') is true (libcurl client #3325).
 * curl_escape/curl_unescape and CURLFile may still register under standard.
 */
final class CurlExtensionPolicy
{
    public static function advertisesHandleClasses(): bool
    {
        return ModuleRegistry::extensionLoaded('curl');
    }
}
