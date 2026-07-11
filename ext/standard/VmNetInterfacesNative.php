<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * net_get_interfaces() for VM — pure PHP via {@see VmNetInterfacesPure} (#8988, #12353).
 *
 * php-src: ext/standard/net.c — PHP_FUNCTION(net_get_interfaces)
 * JIT/AOT: StringNetInterfacesJit.php via NetInterfacesJitHelper
 */
final class VmNetInterfacesNative
{
    public static function available(): bool
    {
        return VmNetInterfacesPure::available();
    }

    /**
     * @return array<string, array{up: bool, unicast: list<array<string, int|string>>}>|false
     */
    public static function collect(): array|false
    {
        return VmNetInterfacesPure::collect();
    }
}
