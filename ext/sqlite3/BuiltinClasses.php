<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;

/**
 * Register sqlite3 builtin classes (php-src ext/sqlite3/sqlite3.stub.php; issue #7269, #3434).
 *
 * SQLite3 query API lands in #3434; exception hierarchy must exist for class_exists/catch patterns.
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        self::registerExceptions($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerExceptions(Context $ctx): void
    {
        if (isset($ctx->classes['sqlite3exception'])) {
            return;
        }

        $sqlite3Exception = new ClassEntry('SQLite3Exception');
        if (isset($ctx->classes['exception'])) {
            $sqlite3Exception->parentLc = 'exception';
        }
        $ctx->classes['sqlite3exception'] = $sqlite3Exception;
    }
}
