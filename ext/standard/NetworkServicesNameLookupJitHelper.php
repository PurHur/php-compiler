<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * getprotobyname()/getservbyname() lookups for compiled JIT/AOT (#13441, php-in-PHP).
 *
 * SSOT: {@see VmNetworkServices}; returns -1 when php-src would return false.
 * php-src: ext/standard/network.c
 */
final class NetworkServicesNameLookupJitHelper
{
    public static function getprotobynameLookup(string $name): int
    {
        $number = VmNetworkServices::getprotobyname($name);

        return false === $number ? -1 : $number;
    }

    public static function getservbynameLookup(string $service, string $protocol): int
    {
        $port = VmNetworkServices::getservbyname($service, $protocol);

        return false === $port ? -1 : $port;
    }
}
