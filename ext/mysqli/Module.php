<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mysqli;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM\Variable;

/**
 * ext/mysqli module entry (php-src ext/mysqli/mysqli.c; #3435).
 *
 * Register procedural mysqli_* functions + mysqli/mysqli_result classes.
 * Live connections use host ext/mysqli as bridge; without it, function_exists()
 * and class_exists() still return true but connect returns false.
 */
class Module extends ModuleAbstract
{
    public function getExtensionVersion(): string
    {
        return '8.2.0';
    }

    public function init(Runtime $runtime): void
    {
        require_once __DIR__.'/bootstrap_mysqli_sql_exception.php';
        require_once __DIR__.'/MysqliExtensionPolicy.php';
        require_once __DIR__.'/MysqliConstants.php';
        require_once __DIR__.'/MysqliClassMethod.php';
        require_once __DIR__.'/MysqliSqlExceptionGetSqlState.php';
        require_once __DIR__.'/BuiltinClasses.php';
        require_once __DIR__.'/VmMysqli.php';
        require_once __DIR__.'/mysqli_connect.php';
        require_once __DIR__.'/mysqli_init.php';
        require_once __DIR__.'/mysqli_query.php';
        require_once __DIR__.'/mysqli_execute_query.php';
        require_once __DIR__.'/mysqli_fetch_assoc.php';
        require_once __DIR__.'/mysqli_fetch_array.php';
        require_once __DIR__.'/mysqli_fetch_row.php';
        require_once __DIR__.'/mysqli_result_fetch_api.php';
        require_once __DIR__.'/mysqli_conn_info_api.php';
        require_once __DIR__.'/mysqli_close.php';
        require_once __DIR__.'/mysqli_connect_errno.php';
        require_once __DIR__.'/mysqli_connect_error.php';
        require_once __DIR__.'/mysqli_free_result.php';
        require_once __DIR__.'/mysqli_real_escape_string.php';
        require_once __DIR__.'/mysqli_num_rows.php';
        require_once __DIR__.'/mysqli_affected_rows.php';
        require_once __DIR__.'/mysqli_error.php';
        require_once __DIR__.'/mysqli_errno.php';
        require_once __DIR__.'/mysqli_error_list.php';
        require_once __DIR__.'/VmMysqliStmt.php';
        require_once __DIR__.'/mysqli_prepare.php';
        require_once __DIR__.'/mysqli_stmt_bind_param.php';
        require_once __DIR__.'/mysqli_stmt_bind_result.php';
        require_once __DIR__.'/mysqli_stmt_close.php';
        require_once __DIR__.'/mysqli_stmt_execute.php';
        require_once __DIR__.'/mysqli_stmt_fetch.php';
        require_once __DIR__.'/mysqli_stmt_introspection_api.php';
        require_once __DIR__.'/MysqliReportMode.php';
        require_once __DIR__.'/mysqli_report.php';
        require_once __DIR__.'/MysqliProceduralLink.php';
        require_once __DIR__.'/mysqli_autocommit.php';
        require_once __DIR__.'/mysqli_begin_transaction.php';
        require_once __DIR__.'/mysqli_commit.php';
        require_once __DIR__.'/mysqli_rollback.php';
        require_once __DIR__.'/mysqli_savepoint.php';
        require_once __DIR__.'/mysqli_release_savepoint.php';
        require_once __DIR__.'/mysqli_refresh.php';
        require_once __DIR__.'/mysqli_get_connection_stats.php';
        require_once __DIR__.'/mysqli_get_links_stats.php';
        require_once __DIR__.'/mysqli_real_connect.php';
        require_once __DIR__.'/mysqli_options.php';
        require_once __DIR__.'/mysqli_set_charset.php';
        require_once __DIR__.'/mysqli_multi_query.php';
        require_once __DIR__.'/mysqli_real_query.php';
        require_once __DIR__.'/mysqli_next_result.php';
        require_once __DIR__.'/mysqli_store_result.php';
        require_once __DIR__.'/mysqli_multi_result_api.php';
        require_once __DIR__.'/mysqli_async_api.php';
        require_once __DIR__.'/mysqli_info.php';
        require_once __DIR__.'/mysqli_stat.php';
        parent::init($runtime);
        if (!MysqliExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach (MysqliConstants::registeredConstants() as $name => $value) {
            $var = new Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getExtensionName(): string
    {
        return MysqliExtensionPolicy::advertisesExtension() ? 'mysqli' : 'standard';
    }

    public function getFunctions(): array
    {
        if (!MysqliExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new mysqli_connect(),
            new mysqli_init(),
            new mysqli_query(),
            new mysqli_execute_query(),
            new mysqli_fetch_assoc(),
            new mysqli_fetch_array(),
            new mysqli_fetch_row(),
            new mysqli_fetch_column(),
            new mysqli_fetch_all(),
            new mysqli_fetch_object(),
            new mysqli_fetch_field(),
            new mysqli_fetch_fields(),
            new mysqli_fetch_field_direct(),
            new mysqli_fetch_lengths(),
            new mysqli_data_seek(),
            new mysqli_field_seek(),
            new mysqli_field_tell(),
            new mysqli_num_fields(),
            new mysqli_insert_id(),
            new mysqli_field_count(),
            new mysqli_sqlstate(),
            new mysqli_warning_count(),
            new mysqli_character_set_name(),
            new mysqli_get_charset(),
            new mysqli_get_server_info(),
            new mysqli_get_host_info(),
            new mysqli_get_proto_info(),
            new mysqli_get_client_info(),
            new mysqli_get_client_version(),
            new mysqli_get_server_version(),
            new mysqli_ssl_set(),
            new mysqli_close(),
            new mysqli_connect_errno(),
            new mysqli_connect_error(),
            new mysqli_free_result(),
            new mysqli_real_escape_string(),
            new mysqli_real_escape_string('mysqli_escape_string'),
            new mysqli_num_rows(),
            new mysqli_affected_rows(),
            new mysqli_error(),
            new mysqli_errno(),
            new mysqli_error_list(),
            new mysqli_report(),
            new mysqli_prepare(),
            new mysqli_stmt_bind_param(),
            new mysqli_stmt_bind_result(),
            new mysqli_stmt_close(),
            new mysqli_stmt_execute(),
            new mysqli_stmt_fetch(),
            new mysqli_stmt_field_count(),
            new mysqli_stmt_param_count(),
            new mysqli_stmt_sqlstate(),
            new mysqli_stmt_errno(),
            new mysqli_stmt_error(),
            new mysqli_stmt_error_list(),
            new mysqli_stmt_insert_id(),
            new mysqli_stmt_num_rows(),
            new mysqli_stmt_affected_rows(),
            new mysqli_stmt_data_seek(),
            new mysqli_stmt_reset(),
            new mysqli_stmt_store_result(),
            new mysqli_stmt_get_result(),
            new mysqli_stmt_free_result(),
            new mysqli_stmt_result_metadata(),
            new mysqli_stmt_attr_get(),
            new mysqli_stmt_attr_set(),
            new mysqli_autocommit(),
            new mysqli_begin_transaction(),
            new mysqli_commit(),
            new mysqli_rollback(),
            new mysqli_savepoint(),
            new mysqli_release_savepoint(),
            new mysqli_refresh(),
            new mysqli_get_connection_stats(),
            new mysqli_get_links_stats(),
            new mysqli_real_connect(),
            new mysqli_options(),
            new mysqli_options('mysqli_set_opt'),
            new mysqli_set_charset(),
            new mysqli_multi_query(),
            new mysqli_real_query(),
            new mysqli_next_result(),
            new mysqli_store_result(),
            new mysqli_use_result(),
            new mysqli_more_results(),
            new mysqli_stmt_more_results(),
            new mysqli_stmt_next_result(),
            new mysqli_poll(),
            new mysqli_reap_async_query(),
            new mysqli_info(),
            new mysqli_stat(),
        ];
    }
}
