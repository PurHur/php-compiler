<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

use PHPCompiler\VM\Context;

/**
 * Register Odbc\Connection / Odbc\Result (php-src ext/odbc; #6293).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!OdbcExtensionPolicy::advertisesClasses()) {
            return;
        }
        VmOdbcConnection::registerClass($ctx);
        VmOdbcResult::registerClass($ctx);
    }
}
