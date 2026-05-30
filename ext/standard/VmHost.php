<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/** Host introspection helpers for stdlib builtins (issue #3465). */
final class VmHost
{
    /** @return string|false */
    public static function gethostname()
    {
        $host = @\gethostname();
        if (false === $host || '' === $host) {
            return false;
        }

        return $host;
    }
}
