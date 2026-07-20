<?php

declare(strict_types=1);

namespace PHPCompiler\ext\odbc;

/**
 * ODBC connection-string curly-brace quoting (php-src main/php_odbc_utils.c; #21256).
 *
 * Shared by ext/odbc and (in php-src) ext/pdo_odbc. Pure string rules — no DSN.
 */
final class VmOdbcConnstr
{
    /** Characters that force quoting per ODBC connection-string grammar. */
    private const SHOULD_QUOTE_CHARS = '[]{}(),;?*=!@';

    /**
     * True when $str is already ODBC-quoted: starts with '{', and every '}'
     * inside is either doubled (escape) or the final terminator.
     *
     * @see php_odbc_connstr_is_quoted()
     */
    public static function isQuoted(string $str): bool
    {
        if ('' === $str || '{' !== $str[0]) {
            return false;
        }
        $length = \strlen($str);
        for ($i = 0; $i < $length; $i++) {
            if ('}' !== $str[$i]) {
                continue;
            }
            // C reads str[i+1] past the last byte as '\0'.
            $next = ($i + 1 < $length) ? $str[$i + 1] : "\0";
            if ('}' === $next) {
                // Skip the second '}' so it is not re-checked as a bare closer.
                $i++;
            } elseif ("\0" !== $next) {
                // Bare '}' not at end → not a valid quoted value.
                return false;
            }
        }

        return true;
    }

    /**
     * True when $str contains a character that ODBC requires bracing around.
     * Does not care whether the value is already quoted.
     *
     * @see php_odbc_connstr_should_quote()
     */
    public static function shouldQuote(string $str): bool
    {
        return false !== \strpbrk($str, self::SHOULD_QUOTE_CHARS);
    }

    /**
     * Wrap $str in '{'…'}', doubling every '}' (SQL-style escape).
     *
     * @see php_odbc_connstr_quote() / php_odbc_connstr_get_quoted_length()
     */
    public static function quote(string $str): string
    {
        $out = '{';
        $len = \strlen($str);
        for ($i = 0; $i < $len; $i++) {
            $ch = $str[$i];
            if ('}' === $ch) {
                $out .= '}}';
            } else {
                $out .= $ch;
            }
        }
        $out .= '}';

        return $out;
    }
}
