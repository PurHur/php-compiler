<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register zip builtin classes (php-src ext/zip/php_zip.c; issue #5869).
 *
 * libzip-backed open/extract semantics land in #3337; v1 skeleton enables class_exists().
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerZipArchive($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerZipArchive(Context $ctx): void
    {
        $ctx->classes['ziparchive'] = new ClassEntry('ZipArchive');
    }
}
