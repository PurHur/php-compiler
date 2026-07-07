<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * sqlite3 extension module entry (php-src ext/sqlite3/sqlite3.c; issue #7269, #3434, #17106).
 *
 * v1 skeleton: withhold extension_loaded/class surface until SQLite3 API lands in #3434.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        if (Sqlite3ExtensionPolicy::advertisesExceptionClass()) {
            require_once __DIR__.'/bootstrap_sqlite3exception.php';
            BuiltinClasses::register($runtime->vmContext);
        }
        parent::init($runtime);
    }
}
