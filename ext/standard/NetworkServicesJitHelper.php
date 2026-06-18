<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT for getprotobynumber()/getservbyport() (#9777, php-in-PHP).
 *
 * Bodies are embedded at link time from {@see VmNetworkServices::buildJitTables()}.
 * php-src: ext/standard/network.c
 */
final class NetworkServicesJitHelper
{
    public static function getprotobynumber(int $number): string
    {
        __PHPC_NS_GETPROTOBYNUMBER_BODY__
    }

    public static function getservbyport(int $port, string $protocol): string
    {
        __PHPC_NS_GETSERVBYPORT_BODY__
    }
}
