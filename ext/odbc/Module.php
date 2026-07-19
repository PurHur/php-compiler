<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * odbc extension module entry (php-src ext/odbc/php_odbc.c; #6293).
 *
 * Phase 1: connect/close/exec/fetch/error surface. Thin unixODBC FFI when
 * libodbc is present (document unixodbc / unixodbc-dev in Docker).
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        return '8.2.0';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!OdbcExtensionPolicy::advertisesBuiltins()) {
            return;
        }
        foreach (OdbcConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
        if (OdbcExtensionPolicy::advertisesClasses()) {
            BuiltinClasses::register($runtime->vmContext);
        }
    }

    public function getFunctions(): array
    {
        if (!OdbcExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        return [
            new odbc_connect(),
            new odbc_pconnect(),
            new odbc_close(),
            new odbc_close_all(),
            new odbc_exec(),
            new odbc_fetch_row(),
            new odbc_result(),
            new odbc_num_rows(),
            new odbc_error(),
            new odbc_errormsg(),
        ];
    }
}
