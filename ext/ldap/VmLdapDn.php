<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * ldap_dn2ufn() / ldap_explode_dn() SSOT (php-src ext/ldap/ldap.c; #22212).
 *
 * Wraps OpenLDAP via {@see VmLdapNative} — same path php-src uses; no new runtime/*.c.
 */
final class VmLdapDn
{
    /**
     * @return array<int|string, int|string>|false
     */
    public static function explodeDn(string $dn, int $withAttrib): array|false
    {
        $parts = VmLdapNative::explodeDn($dn, $withAttrib);
        if (null === $parts) {
            return false;
        }

        // php-src add_assoc_long("count") then add_index_string (GH-22550 / 7809f94).
        $out = ['count' => \count($parts)];
        foreach ($parts as $i => $part) {
            $out[$i] = $part;
        }

        return $out;
    }

    public static function dn2ufn(string $dn): string|false
    {
        $ufn = VmLdapNative::dn2ufn($dn);
        if (null === $ufn) {
            return false;
        }

        return $ufn;
    }

    /**
     * @param array<int|string, int|string> $phpArray
     */
    public static function toHashTable(array $phpArray): HashTable
    {
        $ht = new HashTable();
        foreach ($phpArray as $key => $value) {
            $slot = new Variable();
            if (\is_int($value)) {
                $slot->int($value);
            } else {
                $slot->string((string) $value);
            }
            if (\is_int($key)) {
                $ht->addIndex($key, $slot);
            } else {
                $ht->add((string) $key, $slot);
            }
        }

        return $ht;
    }

    /**
     * @param array<int|string, int|string>|false $result
     */
    public static function explodeResultToVariable(array|false $result): Variable
    {
        $var = new Variable();
        if (false === $result) {
            $var->bool(false);

            return $var;
        }
        $var->array(self::toHashTable($result));

        return $var;
    }

    public static function dn2ufnResultToVariable(string|false $result): Variable
    {
        $var = new Variable();
        if (false === $result) {
            $var->bool(false);

            return $var;
        }
        $var->string($result);

        return $var;
    }
}
