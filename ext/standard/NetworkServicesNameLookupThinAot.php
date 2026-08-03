<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Thin NestedJIT-safe getprotobyname()/getservbyname() (#27060).
 *
 * Avoids {@see VmNetworkServices} static `?array` caches — NestedJIT cannot boxed-store
 * `__hashtable__*` into static properties. Common IANA names only (tcp/udp/…); full
 * /etc/protocols parsing remains on the VM/MCJIT path via VmNetworkServices.
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
        $svc = \strtolower($service);
        $proto = \strtolower($protocol);
        if ('tcp' === $proto) {
            if ('ftp' === $svc) {
                return 21;
            }
            if ('ssh' === $svc) {
                return 22;
            }
            if ('telnet' === $svc) {
                return 23;
            }
            if ('smtp' === $svc) {
                return 25;
            }
            if ('domain' === $svc) {
                return 53;
            }
            if ('http' === $svc) {
                return 80;
            }
            if ('pop3' === $svc) {
                return 110;
            }
            if ('imap' === $svc) {
                return 143;
            }
            if ('https' === $svc) {
                return 443;
            }
        }
        if ('udp' === $proto) {
            if ('domain' === $svc) {
                return 53;
            }
        }

        return -1;
    }
}
