<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * Shared ldap_* semantics (php-src ext/ldap/ldap.c; #3369).
 */
final class VmLdapCore
{
    /**
     * @return Variable|false LDAP\Connection object variable, or false on failure
     */
    public static function connect(?string $uri, ?int $port, Context $ctx): Variable|false
    {
        if (!VmLdapNative::available()) {
            @\trigger_error('ldap_connect(): Could not create session handle', \E_USER_WARNING);

            return false;
        }
        $url = self::normalizeUri($uri, $port);
        $native = VmLdapNative::initialize($url);
        if (null === $native) {
            @\trigger_error('ldap_connect(): Could not create session handle', \E_USER_WARNING);

            return false;
        }

        return VmLdapConnection::wrap($native, $ctx);
    }

    public static function bind(ObjectEntry $connection, ?string $dn, ?string $password): bool
    {
        $ld = VmLdapConnection::native($connection);
        $rc = VmLdapNative::simpleBind($ld, $dn, $password);
        VmLdapConnection::setErrno($connection, $rc);
        if (VmLdapNative::LDAP_SUCCESS !== $rc) {
            @\trigger_error('ldap_bind(): Unable to bind to server: '.VmLdapNative::err2string($rc), \E_USER_WARNING);

            return false;
        }

        return true;
    }

    /**
     * ldap_bind_ext → LDAP\Result|false (php-src ext/ldap/ldap.c; #22164).
     *
     * @return Variable|false
     */
    public static function bindExt(
        ObjectEntry $connection,
        ?string $dn,
        ?string $password,
        Context $ctx
    ): Variable|false {
        $ld = VmLdapConnection::native($connection);
        $info = VmLdapNative::bindExtAsync($ld, $dn, $password);
        $rc = $info['errno'];
        VmLdapConnection::setErrno($connection, $rc);
        if (null === $info['result']) {
            @\trigger_error(\sprintf(
                'ldap_bind_ext(): Unable to bind to server: %s (%d)',
                -1 === $rc ? 'Bind operation failed' : VmLdapNative::err2string($rc),
                $rc
            ), \E_USER_WARNING);

            return false;
        }

        return VmLdapResult::wrapResult($info['result'], $ctx, $connection);
    }

    /**
     * @param list<string> $attributes
     * @return Variable|false
     */
    public static function search(
        ObjectEntry $connection,
        string $base,
        string $filter,
        array $attributes,
        int $attrsonly,
        int $sizelimit,
        int $timelimit,
        int $scope,
        Context $ctx
    ): Variable|false {
        $ld = VmLdapConnection::native($connection);
        $attrs = [] === $attributes ? null : $attributes;
        $res = VmLdapNative::search($ld, $base, $scope, $filter, $attrs, $attrsonly, $sizelimit, $timelimit);
        if (null === $res) {
            $errno = VmLdapNative::getOptionInt($ld, VmLdapNative::LDAP_OPT_ERROR_NUMBER)['value'];
            VmLdapConnection::setErrno($connection, $errno);
            @\trigger_error('ldap_search(): Search: '.VmLdapNative::err2string($errno), \E_USER_WARNING);

            return false;
        }
        VmLdapConnection::setErrno($connection, VmLdapNative::LDAP_SUCCESS);

        return VmLdapResult::wrapResult($res, $ctx, $connection);
    }

    /**
     * php-src ldap_get_entries() array shape as a VM array variable.
     *
     * @return Variable|false
     */
    public static function getEntries(ObjectEntry $connection, ObjectEntry $result): Variable|false
    {
        $ld = VmLdapConnection::native($connection);
        $res = VmLdapResult::resultNative($result);
        $count = VmLdapNative::countEntries($ld, $res);
        if ($count < 0) {
            return false;
        }
        $out = ['count' => $count];
        $entry = VmLdapNative::firstEntry($ld, $res);
        $i = 0;
        while (null !== $entry) {
            $attrs = VmLdapNative::getAttributes($ld, $entry);
            $row = ['count' => \count($attrs)];
            $dn = VmLdapNative::getDn($ld, $entry);
            $row['dn'] = null === $dn ? '' : $dn;
            foreach ($attrs as $attrIndex => $attrName) {
                $values = VmLdapNative::getValuesLen($ld, $entry, $attrName);
                $attrRow = ['count' => \count($values)];
                foreach ($values as $vi => $val) {
                    $attrRow[$vi] = $val;
                }
                $row[$attrName] = $attrRow;
                $row[$attrIndex] = $attrName;
            }
            $out[$i] = $row;
            ++$i;
            $entry = VmLdapNative::nextEntry($ld, $entry);
        }
        $out['count'] = $i;

        return self::importPhpArray($out);
    }

    /**
     * php-src ldap_get_attributes() shape (no dn key; ext/ldap/ldap.c).
     *
     * @return Variable|false
     */
    public static function getAttributesMap(ObjectEntry $connection, ObjectEntry $entry): Variable|false
    {
        $ld = VmLdapConnection::native($connection);
        $entryNative = VmLdapResult::entryNative($entry);
        $attrNames = VmLdapNative::getAttributes($ld, $entryNative);
        $out = ['count' => \count($attrNames)];
        foreach ($attrNames as $attrIndex => $attrName) {
            $values = VmLdapNative::getValuesLen($ld, $entryNative, $attrName);
            $attrRow = ['count' => \count($values)];
            foreach ($values as $vi => $val) {
                $attrRow[$vi] = $val;
            }
            $out[$attrName] = $attrRow;
            $out[$attrIndex] = $attrName;
        }

        return self::importPhpArray($out);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private static function importPhpArray(array $value): Variable
    {
        $ht = new HashTable();
        $isList = \array_is_list($value);
        foreach ($value as $key => $item) {
            $slot = self::importPhpValue($item);
            if ($isList) {
                $ht->addIndex((int) $key, $slot);
            } elseif (\is_int($key)) {
                $ht->addIndex($key, $slot);
            } else {
                $ht->add((string) $key, $slot);
            }
        }
        $var = new Variable();
        $var->array($ht);

        return $var;
    }

    private static function importPhpValue(mixed $value): Variable
    {
        if (\is_array($value)) {
            return self::importPhpArray($value);
        }
        $var = new Variable();
        if (\is_int($value)) {
            $var->int($value);
        } elseif (\is_float($value)) {
            $var->float($value);
        } elseif (\is_bool($value)) {
            $var->bool($value);
        } elseif (null === $value) {
            $var->null();
        } else {
            $var->string((string) $value);
        }

        return $var;
    }

    private static function normalizeUri(?string $uri, ?int $port): string
    {
        if (null === $uri || '' === $uri) {
            return 'ldap://localhost'.(null !== $port && 389 !== $port ? ':'.$port : '');
        }
        if (str_contains($uri, '://')) {
            return $uri;
        }
        // Legacy host[,host…] + port form (php-src ldap_connect host/port).
        $hosts = explode(' ', str_replace(',', ' ', $uri));
        $parts = [];
        foreach ($hosts as $host) {
            $host = trim($host);
            if ('' === $host) {
                continue;
            }
            if (null !== $port && 389 !== $port) {
                $parts[] = 'ldap://'.$host.':'.$port;
            } else {
                $parts[] = 'ldap://'.$host;
            }
        }

        return [] === $parts ? 'ldap://localhost' : implode(' ', $parts);
    }
}
