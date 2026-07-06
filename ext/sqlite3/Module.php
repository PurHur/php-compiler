<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * sqlite3 extension module entry (php-src ext/sqlite3/sqlite3.c; issue #7269, #3434).
 *
 * v1 skeleton: SQLite3Exception hierarchy for introspection/catch; SQLite3 API in #3434.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        require_once __DIR__.'/bootstrap_sqlite3exception.php';
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
    }
}
