<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * UDP DNS exchange for VM — pure PHP via {@see VmDnsUdpPure} (#8937, #8092 completion).
 *
 * php-src: ext/standard/dns.c — UDP DNS transport
 */
final class VmDnsUdpNative
{
    public static function available(): bool
    {
        return VmDnsUdpPure::available();
    }

    public static function exchange(string $nameserver, string $query): ?string
    {
        return VmDnsUdpPure::exchange($nameserver, $query);
    }
}
