<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Host introspection — pure PHP via {@see VmHostPure} (#3465, #5022, #12169).
 *
 * Mirrors {@see JitGethostname} — no Zend host-PHP gethostname() delegation on VM.
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(gethostname)
 */
final class VmHost
{
    public static function available(): bool
    {
        return VmHostPure::available();
    }

    /** @return string|false */
    public static function gethostname()
    {
        return VmHostPure::gethostname();
    }
}
