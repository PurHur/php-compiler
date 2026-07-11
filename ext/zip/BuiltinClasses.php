<?php

declare(strict_types=1);

namespace PHPCompiler\ext\zip;

use PHPCompiler\VM\Context;

/**
 * Register zip builtin classes (php-src ext/zip/php_zip.c; issues #5869, #6413, #6414).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!ZipExtensionPolicy::advertisesExtension()) {
            return;
        }

        $before = array_keys($ctx->classes);
        VmZipArchive::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}
