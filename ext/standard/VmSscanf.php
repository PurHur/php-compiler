<?php

declare(strict_types=1);

/**
 * VM sscanf() subset (%d, %s, %f, %%) — php-src ext/standard/sscanf.c parity smoke.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\Variable;

final class VmSscanf
{
    /**
     * @param list<Variable> $outVars
     */
    public static function parse(string $input, string $format, array $outVars): int
    {
        $outIdx = 0;
        $assigned = 0;
        $inPos = 0;
        $inLen = VmString::byteLength($input);
        $fmtLen = VmString::byteLength($format);

        for ($fpos = 0; $fpos < $fmtLen; ++$fpos) {
            $ch = $format[$fpos];
            if ('%' !== $ch) {
                if ($inPos >= $inLen || $input[$inPos] !== $ch) {
                    return $assigned;
                }
                ++$inPos;
                continue;
            }
            if ($fpos + 1 >= $fmtLen) {
                return $assigned;
            }
            $spec = $format[++$fpos];
            if ('%' === $spec) {
                if ($inPos >= $inLen || $input[$inPos] !== '%') {
                    return $assigned;
                }
                ++$inPos;
                continue;
            }
            if ($outIdx >= \count($outVars)) {
                return $assigned;
            }
            switch ($spec) {
                case 'd':
                    [$val, $consumed] = self::scanInt($input, $inPos, $inLen);
                    if (null === $val) {
                        return $assigned;
                    }
                    self::assignInt($outVars[$outIdx], $val);
                    $inPos += $consumed;
                    ++$outIdx;
                    ++$assigned;
                    break;
                case 's':
                    [$str, $consumed] = self::scanString($input, $inPos, $inLen);
                    if (null === $str) {
                        return $assigned;
                    }
                    self::assignString($outVars[$outIdx], $str);
                    $inPos += $consumed;
                    ++$outIdx;
                    ++$assigned;
                    break;
                case 'f':
                    [$flt, $consumed] = self::scanFloat($input, $inPos, $inLen);
                    if (null === $flt) {
                        return $assigned;
                    }
                    self::assignFloat($outVars[$outIdx], $flt);
                    $inPos += $consumed;
                    ++$outIdx;
                    ++$assigned;
                    break;
                default:
                    throw new \LogicException(
                        'sscanf() unsupported conversion specifier %'.$spec.' in this compiler build'
                    );
            }
        }

        return $assigned;
    }

    /**
     * @return array{0: ?int, 1: int}
     */
    private static function scanInt(string $input, int $pos, int $len): array
    {
        $orig = $pos;
        $pos = self::skipSpace($input, $pos, $len);
        if ($pos >= $len) {
            return [null, 0];
        }
        $negative = false;
        if ('-' === $input[$pos]) {
            $negative = true;
            ++$pos;
        } elseif ('+' === $input[$pos]) {
            ++$pos;
        }
        $value = 0;
        $any = false;
        while ($pos < $len && $input[$pos] >= '0' && $input[$pos] <= '9') {
            $any = true;
            $value = $value * 10 + (ord($input[$pos]) - 48);
            ++$pos;
        }
        if (!$any) {
            return [null, 0];
        }
        if ($negative) {
            $value = -$value;
        }

        return [$value, $pos - $orig];
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private static function scanString(string $input, int $pos, int $len): array
    {
        $orig = $pos;
        $pos = self::skipSpace($input, $pos, $len);
        if ($pos >= $len) {
            return [null, 0];
        }
        $start = $pos;
        while ($pos < $len && !self::isSpace($input[$pos])) {
            ++$pos;
        }
        if ($start === $pos) {
            return [null, 0];
        }

        return [substr($input, $start, $pos - $start), $pos - $orig];
    }

    /**
     * @return array{0: ?float, 1: int}
     */
    private static function scanFloat(string $input, int $pos, int $len): array
    {
        $orig = $pos;
        $pos = self::skipSpace($input, $pos, $len);
        if ($pos >= $len) {
            return [null, 0];
        }
        $start = $pos;
        if ('-' === $input[$pos] || '+' === $input[$pos]) {
            ++$pos;
        }
        $any = false;
        while ($pos < $len && $input[$pos] >= '0' && $input[$pos] <= '9') {
            $any = true;
            ++$pos;
        }
        if ($pos < $len && '.' === $input[$pos]) {
            ++$pos;
            while ($pos < $len && $input[$pos] >= '0' && $input[$pos] <= '9') {
                $any = true;
                ++$pos;
            }
        }
        if (!$any) {
            return [null, 0];
        }
        $slice = substr($input, $start, $pos - $start);

        return [(float) $slice, $pos - $orig];
    }

    private static function skipSpace(string $input, int $pos, int $len): int
    {
        while ($pos < $len && self::isSpace($input[$pos])) {
            ++$pos;
        }

        return $pos;
    }

    private static function isSpace(string $ch): bool
    {
        return ' ' === $ch || "\t" === $ch || "\n" === $ch || "\r" === $ch || "\f" === $ch || "\v" === $ch;
    }

    private static function assignInt(Variable $dest, int $value): void
    {
        $tmp = new Variable();
        $tmp->int($value);
        $dest->copyFrom($tmp);
    }

    private static function assignString(Variable $dest, string $value): void
    {
        $tmp = new Variable();
        $tmp->string($value);
        $dest->copyFrom($tmp);
    }

    private static function assignFloat(Variable $dest, float $value): void
    {
        $tmp = new Variable();
        $tmp->float($value);
        $dest->copyFrom($tmp);
    }
}
