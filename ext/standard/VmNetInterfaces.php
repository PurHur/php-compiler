<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * net_get_interfaces() VM array builder (#6106).
 *
 * php-src: ext/standard/net.c — PHP_FUNCTION(net_get_interfaces)
 */
final class VmNetInterfaces
{
    /**
     * @return HashTable|false
     */
    public static function get()
    {
        $raw = VmNetInterfacesNative::collect();
        if (false === $raw) {
            ErrorReporter::report(
                ErrorReporter::E_WARNING,
                'getifaddrs() failed: unable to enumerate network interfaces'
            );

            return false;
        }

        $root = new HashTable();
        foreach ($raw as $name => $iface) {
            $ifaceHt = new HashTable();
            $up = new Variable();
            $up->bool($iface['up']);
            $ifaceHt->add('up', $up);

            $unicastHt = new HashTable();
            foreach ($iface['unicast'] as $entry) {
                $uHt = new HashTable();
                foreach ($entry as $key => $value) {
                    $slot = new Variable();
                    if (\is_int($value)) {
                        $slot->int($value);
                    } else {
                        $slot->string((string) $value);
                    }
                    $uHt->add((string) $key, $slot);
                }
                $uVar = new Variable();
                $uVar->array($uHt);
                $unicastHt->append($uVar);
            }
            $unicastVar = new Variable();
            $unicastVar->array($unicastHt);
            $ifaceHt->add('unicast', $unicastVar);

            $ifaceVar = new Variable();
            $ifaceVar->array($ifaceHt);
            $root->add($name, $ifaceVar);
        }

        return $root;
    }
}
