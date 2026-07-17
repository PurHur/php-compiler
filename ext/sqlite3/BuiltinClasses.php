<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\VM\Context;

/**
 * Register sqlite3 builtin classes (php-src ext/sqlite3/sqlite3.stub.php; issue #7269, #3434).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        $before = array_keys($ctx->classes);
        if (Sqlite3ExtensionPolicy::advertisesExceptionClass()) {
            self::registerExceptions($ctx);
        }
        if (Sqlite3ExtensionPolicy::advertisesExtension()) {
            VmSQLite3::registerClass($ctx);
            VmSQLite3Result::registerClass($ctx);
            VmSQLite3Stmt::registerClass($ctx);
            self::registerGlobalConstants($ctx);
        }
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }

    private static function registerExceptions(Context $ctx): void
    {
        if (isset($ctx->classes['sqlite3exception'])) {
            return;
        }

        $sqlite3Exception = new \PHPCompiler\VM\ClassEntry('SQLite3Exception');
        if (isset($ctx->classes['exception'])) {
            $sqlite3Exception->parentLc = 'exception';
        }
        $ctx->classes['sqlite3exception'] = $sqlite3Exception;
    }

    /** php-src ext/sqlite3/sqlite3.stub.php global SQLITE3_* constants. */
    private static function registerGlobalConstants(Context $ctx): void
    {
        $globals = [
            'SQLITE3_ASSOC' => Sqlite3Constants::ASSOC,
            'SQLITE3_NUM' => Sqlite3Constants::NUM,
            'SQLITE3_BOTH' => Sqlite3Constants::BOTH,
            'SQLITE3_INTEGER' => VmSqlite3Native::TYPE_INTEGER,
            'SQLITE3_FLOAT' => VmSqlite3Native::TYPE_FLOAT,
            'SQLITE3_TEXT' => VmSqlite3Native::TYPE_TEXT,
            'SQLITE3_BLOB' => VmSqlite3Native::TYPE_BLOB,
            'SQLITE3_NULL' => VmSqlite3Native::TYPE_NULL,
            'SQLITE3_OPEN_READONLY' => Sqlite3Constants::OPEN_READONLY,
            'SQLITE3_OPEN_READWRITE' => Sqlite3Constants::OPEN_READWRITE,
            'SQLITE3_OPEN_CREATE' => Sqlite3Constants::OPEN_CREATE,
        ];
        foreach ($globals as $name => $value) {
            if (isset($ctx->constants[$name])) {
                continue;
            }
            $var = new \PHPCompiler\VM\Variable(\PHPCompiler\VM\Variable::TYPE_INTEGER);
            $var->int($value);
            $ctx->defineConstant($name, $var);
        }
    }
}
