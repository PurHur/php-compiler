<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;

/**
 * pgsql extension module entry (php-src ext/pgsql/pgsql.c; #3741).
 *
 * Requires libpq (libpq5) via FFI — see Docker/dev/ubuntu-22.04/Dockerfile.
 * Registered under {@see standard}; {@code extension_loaded('pgsql')} follows
 * {@see PgsqlExtensionPolicy::advertisesExtension()}.
 */
class Module extends ModuleAbstract
{
    public function getExtensionName(): string
    {
        return 'standard';
    }

    /**
     * @return list<string>
     */
    public function getAdditionalExtensionNames(): array
    {
        if (!PgsqlExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['pgsql'];
    }

    public function getAdditionalExtensionVersions(): array
    {
        if (!PgsqlExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return ['pgsql' => $this->getExtensionVersion()];
    }

    public function getExtensionVersion(): string
    {
        return '8.2.0';
    }

    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (PgsqlExtensionPolicy::advertisesBuiltins()) {
            foreach (PgsqlConstants::registeredConstants() as $name => $value) {
                $var = new \PHPCompiler\VM\Variable();
                $var->int($value);
                $runtime->vmContext->defineConstant($name, $var);
            }
        }
        if (!PgsqlExtensionPolicy::advertisesClasses()) {
            return;
        }
        BuiltinClasses::register($runtime->vmContext);
    }

    public function getFunctions(): array
    {
        if (!PgsqlExtensionPolicy::advertisesBuiltins()) {
            return [];
        }

        require_once __DIR__.'/pg_lo_builtins.php';
        require_once __DIR__.'/pg_copy_meta_builtins.php';
        require_once __DIR__.'/pg_async_builtins.php';
        require_once __DIR__.'/pg_dml_builtins.php';
        require_once __DIR__.'/pg_params_escape_builtins.php';
        require_once __DIR__.'/pg_connection_info_builtins.php';

        return [
            new pg_connect(),
            new pg_close(),
            new pg_query(),
            new pg_fetch_assoc(),
            new pg_fetch_row(),
            new pg_num_rows(),
            new pg_last_error(),
            new pg_trace(),
            new pg_untrace(),
            new pg_lo_create(),
            new pg_lo_unlink(),
            new pg_lo_open(),
            new pg_lo_close(),
            new pg_lo_read(),
            new pg_lo_write(),
            new pg_lo_read_all(),
            new pg_lo_seek(),
            new pg_lo_tell(),
            new pg_lo_truncate(),
            new pg_lo_import(),
            new pg_lo_export(),
            new pg_copy_to(),
            new pg_copy_from(),
            new pg_meta_data(),
            new pg_convert(),
            new pg_field_table(),
            new pg_field_type_oid(),
            new pg_field_is_null(),
            new pg_field_name(),
            new pg_field_size(),
            new pg_field_type(),
            new pg_field_num(),
            new pg_field_prtlen(),
            new pg_socket(),
            new pg_consume_input(),
            new pg_flush(),
            new pg_send_query(),
            new pg_send_query_params(),
            new pg_send_prepare(),
            new pg_send_execute(),
            new pg_get_result(),
            new pg_cancel_query(),
            new pg_get_notify(),
            new pg_result_status(),
            new pg_get_pid(),
            new pg_set_error_verbosity(),
            new pg_set_error_context_visibility(),
            new pg_put_line(),
            new pg_end_copy(),
            new pg_version(),
            new pg_parameter_status(),
            new pg_host(),
            new pg_port(),
            new pg_dbname(),
            new pg_options(),
            new pg_tty(),
            new pg_client_encoding(),
            new pg_set_client_encoding(),
            new pg_ping(),
            new pg_connection_reset(),
            new pg_connection_busy(),
            new pg_connection_status(),
            new pg_transaction_status(),
            new pg_insert(),
            new pg_update(),
            new pg_delete(),
            new pg_select(),
            new pg_query_params(),
            new pg_prepare(),
            new pg_execute(),
            new pg_escape_string(),
            new pg_escape_literal(),
            new pg_escape_identifier(),
            new pg_escape_bytea(),
            new pg_unescape_bytea(),
            new pg_affected_rows(),
            new pg_fetch_all(),
            new pg_num_fields(),
            ...self::php84Functions(),
        ];
    }

    /**
     * @return list<\PHPCompiler\Func\Internal>
     */
    private function php84Functions(): array
    {
        if (!PgsqlExtensionPolicy::advertisesPhp84Helpers()) {
            return [];
        }

        return [
            new pg_change_password(),
            new pg_jit(),
            new pg_put_copy_data(),
            new pg_put_copy_end(),
            new pg_result_memory_size(),
            new pg_set_chunked_rows_size(),
            new pg_socket_poll(),
        ];
    }
}
