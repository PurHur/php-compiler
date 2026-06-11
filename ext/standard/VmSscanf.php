<?php

declare(strict_types=1);

/**
 * VM sscanf() subset (%d, %s, %f, %%) — php-src ext/standard/sscanf.c parity smoke.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

final class VmSscanf
{
    private const ARITY_MISMATCH_MSG = 'Different numbers of variable names and field specifiers';

    private const EXTRA_VAR_MSG = 'Variable is not assigned by any conversion specifiers';

    /**
     * @param list<Variable> $outVars
     */
    public static function parse(string $input, string $format, array $outVars): int
    {
        return self::parseWithConsumed($input, $format, $outVars)[0];
    }

    /**
     * @param list<Variable> $outVars
     *
     * @return array{0: int, 1: int} assigned count and input bytes consumed
     */
    public static function parseWithConsumed(string $input, string $format, array $outVars): array
    {
        if ([] !== $outVars) {
            self::validateOutVarArity($format, \count($outVars));
        }

        $outIdx = 0;
        $assigned = 0;
        $inPos = 0;
        $inLen = VmString::byteLength($input);
        $fmtLen = VmString::byteLength($format);

        for ($fpos = 0; $fpos < $fmtLen; ++$fpos) {
            $ch = $format[$fpos];
            if ('%' !== $ch) {
                if ($inPos >= $inLen || $input[$inPos] !== $ch) {
                    return [$assigned, $inPos];
                }
                ++$inPos;
                continue;
            }
            if ($fpos + 1 >= $fmtLen) {
                return [$assigned, $inPos];
            }
            $spec = $format[++$fpos];
            if ('%' === $spec) {
                if ($inPos >= $inLen || $input[$inPos] !== '%') {
                    return [$assigned, $inPos];
                }
                ++$inPos;
                continue;
            }
            if ($outIdx >= \count($outVars)) {
                return [$assigned, $inPos];
            }
            switch ($spec) {
                case 'd':
                    [$val, $consumed] = self::scanInt($input, $inPos, $inLen);
                    if (null === $val) {
                        return [$assigned, $inPos];
                    }
                    self::assignInt($outVars[$outIdx], $val);
                    $inPos += $consumed;
                    ++$outIdx;
                    ++$assigned;
                    break;
                case 's':
                    [$str, $consumed] = self::scanString($input, $inPos, $inLen);
                    if (null === $str) {
                        return [$assigned, $inPos];
                    }
                    self::assignString($outVars[$outIdx], $str);
                    $inPos += $consumed;
                    ++$outIdx;
                    ++$assigned;
                    break;
                case 'f':
                    [$flt, $consumed] = self::scanFloat($input, $inPos, $inLen);
                    if (null === $flt) {
                        return [$assigned, $inPos];
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

        return [$assigned, $inPos];
    }

    /** Two-arg sscanf(): return parsed values as a list array (php-src ext/standard/sscanf.c, #4201). */
    public static function parseToArray(string $input, string $format): HashTable
    {
        $slots = self::countConversionSpecs($format);
        if (0 === $slots) {
            return new HashTable();
        }
        $temps = [];
        for ($i = 0; $i < $slots; ++$i) {
            $temps[] = new Variable();
        }
        $assigned = self::parse($input, $format, $temps);
        $ht = new HashTable();
        for ($i = 0; $i < $assigned; ++$i) {
            $copy = new Variable();
            $copy->copyFrom($temps[$i]);
            $ht->append($copy);
        }

        return $ht;
    }

    /**
     * PHP 8+ sscanf(): out-variable count must match conversion specifiers (php-src sscanf.c, #4064).
     */
    public static function validateOutVarArity(string $format, int $outVarCount): void
    {
        $specCount = self::countConversionSpecs($format);
        if ($specCount === $outVarCount) {
            return;
        }
        if ($outVarCount < $specCount) {
            throw new \ValueError(self::ARITY_MISMATCH_MSG);
        }

        throw new \ValueError(self::EXTRA_VAR_MSG);
    }

    public static function countConversionSpecs(string $format): int
    {
        $count = 0;
        $len = VmString::byteLength($format);
        for ($fpos = 0; $fpos < $len; ++$fpos) {
            if ('%' !== $format[$fpos]) {
                continue;
            }
            if ($fpos + 1 >= $len) {
                break;
            }
            $spec = $format[++$fpos];
            if ('%' !== $spec) {
                ++$count;
            }
        }

        return $count;
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
