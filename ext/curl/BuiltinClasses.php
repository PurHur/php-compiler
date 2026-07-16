<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register curl builtin classes (php-src ext/curl/curl.stub.php; #6999, #6918, #7266, #19671).
 *
 * PHP 8.4: libcurl handles are {@see CurlHandle} / {@see CurlMultiHandle} / {@see CurlShareHandle}
 * objects (php-src stub — not backed enums). Handle lifecycle in #3325.
 * CURLFile / CURLStringFile gate on {@see CurlExtensionPolicy::advertisesFileClasses()} —
 * no phantom class_exists without ext/curl.
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        if (CurlExtensionPolicy::advertisesFileClasses()) {
            CurlFileBuiltin::register($ctx);
            CurlStringFileBuiltin::register($ctx);
        }
        if (CurlExtensionPolicy::advertisesEasyHandleStubs()) {
            VmCurlEasy::registerClass($ctx);
        }
        if (CurlExtensionPolicy::advertisesShareHandles()) {
            VmCurlShare::registerClass($ctx);
        }
        if (CurlExtensionPolicy::advertisesHandleClasses()) {
            self::registerCurlMultiHandle($ctx);
        }
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    /** php-src ext/curl/curl.stub.php — final class CurlMultiHandle (#7266). */
    private static function registerCurlMultiHandle(Context $ctx): void
    {
        if (isset($ctx->classes['curlmultihandle'])) {
            return;
        }

        $ctx->classes['curlmultihandle'] = new ClassEntry('CurlMultiHandle');
    }
}
