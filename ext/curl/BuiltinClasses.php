<?php

declare(strict_types=1);

namespace PHPCompiler\ext\curl;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register curl builtin classes (php-src ext/curl/curl_file.c; issue #6999, #6918).
 *
 * CURLFile behavior lands in #6918; v1 skeleton enables class_exists().
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerCurlFile($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerCurlFile(Context $ctx): void
    {
        $ctx->classes['curlfile'] = new ClassEntry('CURLFile');
    }
}
