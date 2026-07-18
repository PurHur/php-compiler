<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pgsql;

use PHPCompiler\VM\Context;

/**
 * Register PgSql\Connection / PgSql\Result (php-src ext/pgsql/pgsql.stub.php; #3741).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!PgsqlExtensionPolicy::advertisesClasses()) {
            return;
        }
        VmPgsqlConnection::registerClass($ctx);
        VmPgsqlResult::registerClass($ctx);
    }
}
