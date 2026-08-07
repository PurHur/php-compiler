<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Context;
use PHPCompiler\VM\ErrorReporter;

/**
 * ini_parse_quantity() — Zend zend_ini_parse_quantity (Zend/zend_ini.c, ext/standard/basic_functions.c).
 */
final class VmIniQuantity
{
    private static function isWhitespace(string $c): bool
    {
        return ' ' === $c || "\t" === $c || "\n" === $c || "\r" === $c || "\v" === $c || "\f" === $c;
    }

    /**
     * Parse ini byte quantity shorthand to signed int (php-src zend_ini_parse_quantity).
     */
    public static function parseQuantity(string $value, ?Context $ctx = null): int
    {
        $original = $value;
        $len = \strlen($value);
        $start = 0;
        while ($start < $len && self::isWhitespace($value[$start])) {
            ++$start;
        }
        $end = $len;
        while ($end > $start && self::isWhitespace($value[$end - 1])) {
            --$end;
        }

        if ($start === $end) {
            return 0;
        }

        $slice = substr($value, $start, $end - $start);
        $negative = false;
        $pos = 0;
        $sliceLen = \strlen($slice);

        if ('+' === $slice[0]) {
            ++$pos;
        } elseif ('-' === $slice[0]) {
            $negative = true;
            ++$pos;
        }

        if ($pos >= $sliceLen || !ctype_digit($slice[$pos])) {
            self::warn(
                $ctx,
                \sprintf(
                    'Invalid quantity "%s": no valid leading digits, interpreting as "0" for backwards compatibility',
                    $original
                )
            );

            return 0;
        }

        // base 0 = C strtoul auto (leading 0 → octal); explicit 0x/0o/0b set below.
        // php-src: Zend/zend_ini.c zend_ini_parse_quantity_internal (#28763).
        $base = 0;
        if ('0' === $slice[$pos] && ($pos + 1) < $sliceLen && !ctype_digit($slice[$pos + 1])) {
            $prefix = $slice[$pos + 1];
            if (\in_array($prefix, ['g', 'G', 'm', 'M', 'k', 'K'], true)) {
                goto evaluation;
            }
            switch ($prefix) {
                case 'x':
                case 'X':
                    $base = 16;
                    $pos += 2;
                    break;
                case 'o':
                case 'O':
                    $base = 8;
                    $pos += 2;
                    break;
                case 'b':
                case 'B':
                    $base = 2;
                    $pos += 2;
                    break;
                default:
                    self::warn(
                        $ctx,
                        \sprintf(
                            'Invalid prefix "0%s", interpreting as "0" for backwards compatibility',
                            $prefix
                        )
                    );

                    return 0;
            }
            if ($pos >= $sliceLen) {
                self::warn(
                    $ctx,
                    \sprintf(
                        'Invalid quantity "%s": no digits after base prefix, interpreting as "0" for backwards compatibility',
                        $original
                    )
                );

                return 0;
            }
        }

        evaluation:
        // Resolve strtoul(base=0): leading 0 + digit → octal (legacy); else decimal.
        if (0 === $base) {
            if (
                '0' === $slice[$pos]
                && ($pos + 1) < $sliceLen
                && ctype_digit($slice[$pos + 1])
            ) {
                $base = 8;
            } else {
                $base = 10;
            }
        }

        $digitsEnd = $pos;
        while ($digitsEnd < $sliceLen && self::isDigitInBase($slice[$digitsEnd], $base)) {
            ++$digitsEnd;
        }

        $numericPart = substr($slice, $pos, $digitsEnd - $pos);
        if ('' === $numericPart) {
            self::warn(
                $ctx,
                \sprintf(
                    'Invalid quantity "%s": no valid leading digits, interpreting as "0" for backwards compatibility',
                    $original
                )
            );

            return 0;
        }

        $retval = self::parseUnsignedDigits($numericPart, $base);
        if ($negative) {
            if (PHP_INT_MIN === -$retval && '9223372036854775808' === $numericPart && 10 === $base) {
                $retval = PHP_INT_MIN;
            } else {
                $retval = -$retval;
            }
        }

        while ($digitsEnd < $sliceLen && self::isWhitespace($slice[$digitsEnd])) {
            ++$digitsEnd;
        }

        if ($digitsEnd === $sliceLen) {
            return (int) $retval;
        }

        $suffix = $slice[$sliceLen - 1];
        $factor = match ($suffix) {
            'g', 'G' => 1 << 30,
            'm', 'M' => 1 << 20,
            'k', 'K' => 1 << 10,
            default => null,
        };

        if (null === $factor) {
            self::warn(
                $ctx,
                \sprintf(
                    'Invalid quantity "%s": unknown multiplier "%s", interpreting as "%s" for backwards compatibility',
                    $original,
                    $suffix,
                    substr($slice, 0, $digitsEnd)
                )
            );

            return (int) $retval;
        }

        $multiplied = $retval * $factor;
        if ($digitsEnd !== $sliceLen - 1) {
            self::warn(
                $ctx,
                \sprintf(
                    'Invalid quantity "%s", interpreting as "%s%s" for backwards compatibility',
                    $original,
                    substr($slice, 0, $digitsEnd),
                    $suffix
                )
            );
        }

        if (!\is_int($multiplied)) {
            self::warn(
                $ctx,
                \sprintf(
                    'Invalid quantity "%s": value is out of range, using overflow result for backwards compatibility',
                    $original
                )
            );
        }

        return (int) $multiplied;
    }

    /** Digit predicate matching C strtoul for the active base (zend_ini_parse_quantity). */
    private static function isDigitInBase(string $c, int $base): bool
    {
        return match ($base) {
            2 => '0' === $c || '1' === $c,
            8 => $c >= '0' && $c <= '7',
            16 => (bool) ctype_xdigit($c),
            default => (bool) ctype_digit($c),
        };
    }

    private static function parseUnsignedDigits(string $digits, int $base): int|float
    {
        if (10 === $base) {
            if (\strlen($digits) > 18) {
                return (float) $digits;
            }

            return (int) $digits;
        }

        return (int) \base_convert($digits, $base, 10);
    }

    private static function warn(?Context $ctx, string $message): void
    {
        if (null === $ctx) {
            return;
        }
        $ctx->errors->triggerError(
            $message,
            ErrorReporter::E_WARNING,
            null,
            $ctx
        );
    }
}
