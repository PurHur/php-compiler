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

        return [
            new pg_connect(),
            new pg_close(),
            new pg_query(),
            new pg_fetch_assoc(),
            new pg_fetch_row(),
            new pg_num_rows(),
            new pg_last_error(),
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
