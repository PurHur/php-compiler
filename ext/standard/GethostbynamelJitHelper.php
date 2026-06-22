<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * gethostbynamel() DNS resolve for compiled JIT/AOT modules (#9382, php-in-PHP).
 *
 * SSOT: {@see VmDns::resolveHostnameIpv4List}; indexed IPv4 strings are assembled into
 * native hashtables in {@see \PHPCompiler\JIT\Builtin\GethostbynamelRuntime}.
 * php-src: ext/standard/dns.c — PHP_FUNCTION(gethostbynamel)
 */
final class GethostbynamelJitHelper
{
    public static function ipCount(string $hostname): int
    {
        return \count(VmDns::resolveHostnameIpv4List($hostname));
    }

    public static function ipAt(string $hostname, int $index): string
    {
        $ips = VmDns::resolveHostnameIpv4List($hostname);

        return $ips[$index] ?? '';
    }
}
