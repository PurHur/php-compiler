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
     * ldap_compare → true / false / -1 (php-src; #22177).
     */
    public static function compare(
        ObjectEntry $connection,
        string $dn,
        string $attribute,
        string $value
    ): bool|int {
        $ld = VmLdapConnection::native($connection);
        $rc = VmLdapNative::compareExtSync($ld, $dn, $attribute, $value);
        VmLdapConnection::setErrno($connection, $rc);
        if (VmLdapNative::LDAP_COMPARE_TRUE === $rc) {
            return true;
        }
        if (VmLdapNative::LDAP_COMPARE_FALSE === $rc) {
            return false;
        }
        @\trigger_error('ldap_compare(): Compare: '.VmLdapNative::err2string($rc), \E_USER_WARNING);

        return -1;
    }

    /**
     * @return string|false
     */
    public static function getDn(ObjectEntry $connection, ObjectEntry $entry): string|false
    {
        $ld = VmLdapConnection::native($connection);
        $dn = VmLdapNative::getDn($ld, VmLdapResult::entryNative($entry));
        if (null === $dn) {
            return false;
        }

        return $dn;
    }

    /**
     * php-src ldap_get_values / ldap_get_values_len array shape.
     *
     * @return Variable|false
     */
    public static function getValues(ObjectEntry $connection, ObjectEntry $entry, string $attribute): Variable|false
    {
        $ld = VmLdapConnection::native($connection);
        $values = VmLdapNative::getValuesLen($ld, VmLdapResult::entryNative($entry), $attribute);
        // OpenLDAP returns NULL on missing attr — treat empty as failure like php-src when errno set.
        if ([] === $values) {
            $errno = VmLdapNative::getOptionInt($ld, VmLdapNative::LDAP_OPT_ERROR_NUMBER)['value'];
            if (VmLdapNative::LDAP_SUCCESS !== $errno && 0 !== $errno) {
                @\trigger_error(
                    'ldap_get_values(): Cannot get the value(s) of attribute '.VmLdapNative::err2string($errno),
                    \E_USER_WARNING
                );

                return false;
            }
        }
        $out = ['count' => \count($values)];
        foreach ($values as $i => $val) {
            $out[$i] = $val;
        }

        return self::importPhpArray($out);
    }

    /**
     * @return string|false
     */
    public static function firstAttribute(ObjectEntry $connection, ObjectEntry $entry): string|false
    {
        $ld = VmLdapConnection::native($connection);
        $info = VmLdapNative::firstAttribute($ld, VmLdapResult::entryNative($entry));
        VmLdapResult::setEntryBer($entry, $info['ber']);
        if (null === $info['attr']) {
            return false;
        }

        return $info['attr'];
    }

    /**
     * @return string|false
     */
    public static function nextAttribute(ObjectEntry $connection, ObjectEntry $entry): string|false
    {
        $ber = VmLdapResult::entryBer($entry);
        if (null === $ber) {
            @\trigger_error(
                'ldap_next_attribute(): Called before calling ldap_first_attribute() or no attributes found in result entry',
                \E_USER_WARNING
            );

            return false;
        }
        $ld = VmLdapConnection::native($connection);
        $attr = VmLdapNative::nextAttribute($ld, VmLdapResult::entryNative($entry), $ber);
        if (null === $attr) {
            return false;
        }

        return $attr;
    }

    /**
     * @param list<string> $referralsOut filled when wanted
     * @return array{ok: bool, errcode: int, matched_dn: string, error_message: string, referrals: list<string>}|false
     */
    public static function parseResult(
        ObjectEntry $connection,
        ObjectEntry $result,
        bool $wantMatched,
        bool $wantErrmsg,
        bool $wantReferrals
    ): array|false {
        $ld = VmLdapConnection::native($connection);
        $parsed = VmLdapNative::parseResult(
            $ld,
            VmLdapResult::resultNative($result),
            $wantMatched,
            $wantErrmsg,
            $wantReferrals
        );
        VmLdapConnection::setErrno($connection, $parsed['errno']);
        if (!$parsed['ok']) {
            @\trigger_error(
                'ldap_parse_result(): Unable to parse result: '.VmLdapNative::err2string($parsed['errno']),
                \E_USER_WARNING
            );

            return false;
        }

        return [
            'ok' => true,
            'errcode' => $parsed['errcode'],
            'matched_dn' => $parsed['matched_dn'],
            'error_message' => $parsed['error_message'],
            'referrals' => $parsed['referrals'],
        ];
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
