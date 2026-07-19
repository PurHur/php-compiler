<?php

declare(strict_types=1);

namespace PHPCompiler\ext\dba;

use PHPCompiler\VM\Context;

/**
 * Register Dba\Connection (php-src ext/dba/dba.stub.php; #4422).
 */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!DbaExtensionPolicy::advertisesClasses()) {
            return;
        }
        VmDbaConnection::registerClass($ctx);
    }
}
