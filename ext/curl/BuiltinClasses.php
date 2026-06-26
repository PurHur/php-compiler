<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register curl builtin classes (php-src ext/curl/curl.stub.php; #6999, #6918, #7266).
 *
 * PHP 8.4: libcurl handles are {@see CurlHandle} / {@see CurlMultiHandle} / {@see CurlShareHandle}
 * objects (php-src stub — not backed enums). Handle lifecycle in #3325.
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerCurlFile($ctx);
        if (CurlExtensionPolicy::advertisesHandleClasses()) {
            self::registerCurlHandle($ctx);
            self::registerCurlMultiHandle($ctx);
            self::registerCurlShareHandle($ctx);
        }
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerCurlFile(Context $ctx): void
    {
        if (isset($ctx->classes['curlfile'])) {
            return;
        }

        $ctx->classes['curlfile'] = new ClassEntry('CURLFile');
    }

    /** php-src ext/curl/curl.stub.php — final class CurlHandle (#7266). */
    private static function registerCurlHandle(Context $ctx): void
    {
        if (isset($ctx->classes['curlhandle'])) {
            return;
        }

        $ctx->classes['curlhandle'] = new ClassEntry('CurlHandle');
    }

    /** php-src ext/curl/curl.stub.php — final class CurlMultiHandle (#7266). */
    private static function registerCurlMultiHandle(Context $ctx): void
    {
        if (isset($ctx->classes['curlmultihandle'])) {
            return;
        }

        $ctx->classes['curlmultihandle'] = new ClassEntry('CurlMultiHandle');
    }

    /** php-src ext/curl/curl.stub.php — final class CurlShareHandle (#7266). */
    private static function registerCurlShareHandle(Context $ctx): void
    {
        if (isset($ctx->classes['curlsharehandle'])) {
            return;
        }

        $ctx->classes['curlsharehandle'] = new ClassEntry('CurlShareHandle');
    }
}
