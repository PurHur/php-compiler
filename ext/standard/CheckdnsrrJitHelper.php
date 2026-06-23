<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * checkdnsrr() DNS probe for compiled JIT/AOT modules (#9379, php-in-PHP).
 *
 * SSOT: {@see VmDns::checkdnsrr}; VM path uses the same helper.
 * php-src: ext/standard/dns.c — PHP_FUNCTION(checkdnsrr)
 */
final class CheckdnsrrJitHelper
{
    public static function check(string $hostname, string $type): bool
    {
        return VmDns::checkdnsrr($hostname, $type);
    }
}
