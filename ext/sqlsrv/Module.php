<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlsrv;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * sqlsrv extension module entry (Microsoft sqlsrv / php-src ext/sqlsrv; #6577).
 *
 * Phase-1 procedural API — PHP-in-PHP; host ext/sqlsrv bridge when present.
 * Without the Microsoft ODBC driver, connect returns false and sqlsrv_errors() is populated.
 */
class Module extends ModuleAbstract
{
    private const SQLSRV_VERSION = '5.12.0';

    public function getExtensionVersion(): string
    {
        return self::SQLSRV_VERSION;
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!SqlsrvExtensionPolicy::advertisesBuiltins()) {
            return;
        }
        VmSqlsrvConnection::registerClass($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!SqlsrvExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        require_once __DIR__.'/sqlsrv_builtins.php';

        return [
            new sqlsrv_connect(),
            new sqlsrv_close(),
            new sqlsrv_query(),
            new sqlsrv_fetch_array(),
            new sqlsrv_errors(),
        ];
    }
}
