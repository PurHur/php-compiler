<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin NestedJIT-safe getprotobyname()/getservbyname() (#27060, #27198).
 *
 * Avoids {@see VmNetworkServices} static `?array` caches — NestedJIT cannot boxed-store
 * `__hashtable__*` into static properties.
 *
 * getservbyname: thin AOT always returns -1 (false). NestedJIT cannot honor `/etc/services`
 * without inventing ports (prior IANA table returned 80 with no services DB — #27198) or
 * without NestedJIT-unsafe file parsing. VM / non-thin
 * {@see NetworkServicesNameLookupJitHelper} still parses the real services file.
 *
 * php-src: ext/standard/network.c
 */
final class NetworkServicesNameLookupJitHelper
{
    public static function getprotobynameLookup(string $name): int
    {
        $key = \strtolower($name);
        if ('ip' === $key) {
            return 0;
        }
        if ('icmp' === $key) {
            return 1;
        }
        if ('igmp' === $key) {
            return 2;
        }
        if ('tcp' === $key) {
            return 6;
        }
        if ('udp' === $key) {
            return 17;
        }
        if ('ipv6' === $key) {
            return 41;
        }
        if ('icmpv6' === $key) {
            return 58;
        }

        return -1;
    }

    public static function getservbynameLookup(string $service, string $protocol): int
    {
        // Thin AOT: never invent ports when /etc/services is missing (#27198).
        // Full lookup stays on the VM path (VmNetworkServices).
        unset($service, $protocol);

        return -1;
    }
}
