<?php

declare(strict_types=1);

namespace PHPCompiler\ext\snmp;

use PHPCompiler\VM\Context;

/** Register snmp builtin classes (php-src ext/snmp/snmp_class.c; #6070). */
final class BuiltinClasses
{
    public static function register(Context $ctx): void
    {
        if (!SnmpExtensionPolicy::advertisesExtension()) {
            return;
        }

        $before = array_keys($ctx->classes);
        VmSnmp::registerClass($ctx);
        foreach (array_diff(array_keys($ctx->classes), $before) as $lc) {
            $ctx->classes[$lc]->isInternal = true;
        }
    }
}