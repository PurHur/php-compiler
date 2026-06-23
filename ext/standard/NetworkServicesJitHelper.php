<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT for getprotobynumber()/getservbyport() (#9777, php-in-PHP).
 *
 * JIT/AOT compiles table lookups from {@see VmNetworkServices::buildJitTables()};
 * VM/bootstrap uses the VmNetworkServices delegation bodies below (#9777).
 * php-src: ext/standard/network.c
 */
final class NetworkServicesJitHelper
{
    public static function getprotobynumber(int $number): string
    {
        $name = VmNetworkServices::getprotobynumber($number);

        return false === $name ? '' : (string) $name;
    }

    public static function getservbyport(int $port, string $protocol): string
    {
        $name = VmNetworkServices::getservbyport($port, $protocol);

        return false === $name ? '' : (string) $name;
    }
}
