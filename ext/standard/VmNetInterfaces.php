<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * net_get_interfaces() VM array builder (#6106, #23715).
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

            // php-src net.c: zend_hash_str_add("unicast") then add_assoc_bool("up") (#28140)
            $unicastHt = new HashTable();
            foreach ($iface['unicast'] as $entry) {
                $uHt = self::unicastEntryToHashTable($entry);
                $uVar = new Variable();
                $uVar->array($uHt);
                $unicastHt->append($uVar);
            }
            $unicastVar = new Variable();
            $unicastVar->array($unicastHt);
            $ifaceHt->add('unicast', $unicastVar);

            $up = new Variable();
            $up->bool($iface['up']);
            $ifaceHt->add('up', $up);

            $ifaceVar = new Variable();
            $ifaceVar->array($ifaceHt);
            $root->add($name, $ifaceVar);
        }

        return $root;
    }

    /**
     * Build one unicast row (php-src iface_append_unicast key order).
     *
     * @param array<string, int|string> $entry
     */
    private static function unicastEntryToHashTable(array $entry): HashTable
    {
        $uHt = new HashTable();
        if (\array_key_exists('flags', $entry)) {
            $slot = new Variable();
            $slot->int((int) $entry['flags']);
            $uHt->add('flags', $slot);
        }
        if (\array_key_exists('family', $entry)) {
            $slot = new Variable();
            $slot->int((int) $entry['family']);
            $uHt->add('family', $slot);
        }
        foreach (['address', 'netmask', 'broadcast', 'ptp'] as $key) {
            if (!\array_key_exists($key, $entry)) {
                continue;
            }
            $slot = new Variable();
            $slot->string((string) $entry[$key]);
            $uHt->add($key, $slot);
        }

        return $uHt;
    }
}
