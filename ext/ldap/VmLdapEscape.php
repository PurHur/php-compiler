<?php

declare(strict_types=1);

namespace PHPCompiler\ext\ldap;

/**
 * ldap_escape() core (php-src ext/ldap/ldap.c php_ldap_do_escape; issue #6352).
 */
final class VmLdapEscape
{
    private const HEX = '0123456789abcdef';

    public static function escape(string $value, string $ignore, int $flags): string
    {
        if ('' === $value) {
            return '';
        }

        $map = array_fill(0, 256, false);
        $haveCharList = false;

        if (0 !== ($flags & LdapConstants::LDAP_ESCAPE_FILTER)) {
            $haveCharList = true;
            self::setMapChars($map, "\\*()\0", true);
        }

        if (0 !== ($flags & LdapConstants::LDAP_ESCAPE_DN)) {
            $haveCharList = true;
            self::setMapChars($map, "\\,=+<>;\"#\r", true);
        }

        if (!$haveCharList) {
            for ($i = 0; $i < 256; ++$i) {
                $map[$i] = true;
            }
        }

        if ('' !== $ignore) {
            self::setMapChars($map, $ignore, false);
        }

        return self::doEscape($map, $value, $flags);
    }

    /**
     * @param array<int, bool> $map
     */
    private static function doEscape(array $map, string $value, int $flags): string
    {
        $valuelen = \strlen($value);
        $len = 0;
        $maxLen = \PHP_INT_MAX;

        for ($i = 0; $i < $valuelen; ++$i) {
            $addend = $map[\ord($value[$i])] ? 3 : 1;
            if ($len > $maxLen - $addend) {
                throw new \ValueError('ldap_escape(): Argument #1 ($value) is too long');
            }
            $len += $addend;
        }

        if (0 !== ($flags & LdapConstants::LDAP_ESCAPE_DN) && ' ' === $value[0]) {
            if ($len > $maxLen - 2) {
                throw new \ValueError('ldap_escape(): Argument #1 ($value) is too long');
            }
            $len += 2;
        }

        if (0 !== ($flags & LdapConstants::LDAP_ESCAPE_DN) && $valuelen > 1 && ' ' === $value[$valuelen - 1]) {
            if ($len > $maxLen - 2) {
                throw new \ValueError('ldap_escape(): Argument #1 ($value) is too long');
            }
            $len += 2;
        }

        $out = '';
        for ($i = 0; $i < $valuelen; ++$i) {
            $v = \ord($value[$i]);
            if ($map[$v]
                || (0 !== ($flags & LdapConstants::LDAP_ESCAPE_DN)
                    && (0 === $i || $i + 1 === $valuelen)
                    && 0x20 === $v)) {
                $out .= '\\'.self::HEX[$v >> 4].self::HEX[$v & 0x0f];
            } else {
                $out .= $value[$i];
            }
        }

        return $out;
    }

    /**
     * @param array<int, bool> $map
     */
    private static function setMapChars(array &$map, string $chars, bool $escape): void
    {
        $charslen = \strlen($chars);
        for ($i = 0; $i < $charslen; ++$i) {
            $map[\ord($chars[$i])] = $escape;
        }
    }
}
