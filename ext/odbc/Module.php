<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * odbc extension module entry (php-src ext/odbc/php_odbc.c; #6293 / #21258).
 *
 * Connect/close/exec/fetch/error + PHP 8.2 connection-string helpers +
 * prepare/execute/fetch_array/tables/columns/field_* + autocommit/commit/rollback.
 * Thin unixODBC FFI when libodbc is present (document unixodbc / libsqliteodbc in Docker).
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

        require_once __DIR__.'/odbc_prepare_fetch_builtins.php';
        require_once __DIR__.'/odbc_txn_builtins.php';

        return [
            new odbc_connect(),
            new odbc_pconnect(),
            new odbc_close(),
            new odbc_close_all(),
            new odbc_connection_string_is_quoted(),
            new odbc_connection_string_should_quote(),
            new odbc_connection_string_quote(),
            new odbc_exec(),
            new odbc_prepare(),
            new odbc_execute(),
            new odbc_fetch_row(),
            new odbc_fetch_array(),
            new odbc_fetch_object(),
            new odbc_fetch_into(),
            new odbc_result(),
            new odbc_num_rows(),
            new odbc_num_fields(),
            new odbc_field_name(),
            new odbc_field_type(),
            new odbc_field_len(),
            new odbc_field_num(),
            new odbc_tables(),
            new odbc_columns(),
            new odbc_free_result(),
            new odbc_autocommit(),
            new odbc_commit(),
            new odbc_rollback(),
            new odbc_error(),
            new odbc_errormsg(),
        ];
    }
}