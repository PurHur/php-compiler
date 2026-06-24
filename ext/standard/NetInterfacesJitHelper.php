<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * net_get_interfaces() for compiled JIT/AOT modules (#8988, php-in-PHP).
 *
 * SSOT: {@see VmNetInterfaces::get} → {@see VmNetInterfacesPure} / {@see VmNetInterfacesNative}.
 * php-src: ext/standard/net.c — PHP_FUNCTION(net_get_interfaces)
 */
final class NetInterfacesJitHelper
{
    public static function resolve(): ?HashTable
    {
        $ifaces = VmNetInterfaces::get();

        return false === $ifaces ? null : $ifaces;
    }
}
