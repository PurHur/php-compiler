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
     * @return array{0: int, 1: int, 2: int} assigned count (includes successful %* suppressions),
     *                                         input bytes consumed, and number of out-vars stored
     */
    public static function parseWithConsumed(string $input, string $format, array $outVars): array
    {
        if ([] !== $outVars) {
            self::validateOutVarArity($format, \count($outVars));
        }
        self::validateConversionCharacters($format);

        $outIdx = 0;
        $assigned = 0;
        $inPos = 0;
        $inLen = VmString::byteLength($input);
        $fmtLen = VmString::byteLength($format);

        for ($fpos = 0; $fpos < $fmtLen; ++$fpos) {
            $ch = $format[$fpos];
            if ('%' !== $ch) {
                if (self::isSpace($ch)) {
                    $inPos = self::skipSpace($input, $inPos, $inLen);
                    continue;
                }
                if ($inPos >= $inLen || $input[$inPos] !== $ch) {
                    return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                }
                ++$inPos;
                continue;
            }
            if ($fpos + 1 >= $fmtLen) {
                return self::finishParse($outVars, $outIdx, $assigned, $inPos);
            }
            ++$fpos;
            [$width, $fpos] = self::parseFieldWidth($format, $fpos, $fmtLen);
            if ($fpos >= $fmtLen) {
                return self::finishParse($outVars, $outIdx, $assigned, $inPos);
            }
            $suppress = false;
            if ('*' === $format[$fpos]) {
                $suppress = true;
                ++$fpos;
                if ($fpos >= $fmtLen) {
                    return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                }
            }
            if ('[' === $format[$fpos]) {
                [$matcher, $fpos] = self::parseScansetMatcher($format, $fpos, $fmtLen);
                if (!$suppress && $outIdx >= \count($outVars)) {
                    return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                }
                [$str, $consumed] = self::scanScansetMatch($input, $inPos, $inLen, $matcher, $width);
                if (null === $str) {
                    return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                }
                // php-src formatted_io.c — successful %* conversions count toward the return value.
                ++$assigned;
                if (!$suppress) {
                    self::assignString($outVars[$outIdx], $str);
                    ++$outIdx;
                }
                $inPos += $consumed;
                // parseScansetMatcher() leaves $fpos on the char after ']'; back up so the
                // for-loop increment does not skip a following '%' (php-src formatted_io.c).
                --$fpos;
                continue;
            }
            $spec = $format[$fpos];
            if ('%' === $spec) {
                if ($inPos >= $inLen || $input[$inPos] !== '%') {
                    return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                }
                ++$inPos;
                continue;
            }
            if (!$suppress && $outIdx >= \count($outVars)) {
                return self::finishParse($outVars, $outIdx, $assigned, $inPos);
            }
            switch ($spec) {
                case 'D':
                case 'd':
                    [$val, $consumed] = self::scanInt($input, $inPos, $inLen, $width);
                    if (null === $val) {
                        return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                    }
                    ++$assigned;
                    if (!$suppress) {
                        self::assignInt($outVars[$outIdx], $val);
                        ++$outIdx;
                    }
                    $inPos += $consumed;
                    break;
                case 'i':
                    [$val, $consumed] = self::scanAutoBaseInt($input, $inPos, $inLen, $width);
                    if (null === $val) {
                        return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                    }
                    ++$assigned;
                    if (!$suppress) {
                        self::assignInt($outVars[$outIdx], $val);
                        ++$outIdx;
                    }
                    $inPos += $consumed;
                    break;
                case 'u':
                    [$val, $consumed, $asString] = self::scanUnsigned($input, $inPos, $inLen, $width);
                    if (null === $val && null === $asString) {
                        return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                    }
                    ++$assigned;
                    if (!$suppress) {
                        if (null !== $asString) {
                            self::assignString($outVars[$outIdx], $asString);
                        } else {
                            self::assignInt($outVars[$outIdx], $val);
                        }
                        ++$outIdx;
                    }
                    $inPos += $consumed;
                    break;
                case 'x':
                case 'X':
                    [$val, $consumed] = self::scanHex($input, $inPos, $inLen, $width);
                    if (null === $val) {
                        return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                    }
                    ++$assigned;
                    if (!$suppress) {
                        self::assignInt($outVars[$outIdx], $val);
                        ++$outIdx;
                    }
                    $inPos += $consumed;
                    break;
                case 'o':
                    [$val, $consumed] = self::scanOct($input, $inPos, $inLen, $width);
                    if (null === $val) {
                        return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                    }
                    ++$assigned;
                    if (!$suppress) {
                        self::assignInt($outVars[$outIdx], $val);
                        ++$outIdx;
                    }
                    $inPos += $consumed;
                    break;
                case 'c':
                    [$str, $consumed] = self::scanChar($input, $inPos, $inLen, $width);
                    if (null === $str) {
                        return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                    }
                    ++$assigned;
                    if (!$suppress) {
                        self::assignString($outVars[$outIdx], $str);
                        ++$outIdx;
                    }
                    $inPos += $consumed;
                    break;
                case 's':
                    [$str, $consumed] = self::scanString($input, $inPos, $inLen, $width);
                    if (null === $str) {
                        return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                    }
                    ++$assigned;
                    if (!$suppress) {
                        self::assignString($outVars[$outIdx], $str);
                        ++$outIdx;
                    }
                    $inPos += $consumed;
                    break;
                case 'f':
                    [$flt, $consumed] = self::scanFloat($input, $inPos, $inLen, $width, true);
                    if (null === $flt) {
                        return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                    }
                    ++$assigned;
                    if (!$suppress) {
                        self::assignFloat($outVars[$outIdx], $flt);
                        ++$outIdx;
                    }
                    $inPos += $consumed;
                    break;
                case 'e':
                case 'E':
                case 'g':
                case 'G':
                    [$flt, $consumed] = self::scanFloat($input, $inPos, $inLen, $width, true);
                    if (null === $flt) {
                        return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                    }
                    ++$assigned;
                    if (!$suppress) {
                        self::assignFloat($outVars[$outIdx], $flt);
                        ++$outIdx;
                    }
                    $inPos += $consumed;
                    break;
                case 'n':
                    // %n always counts; assignment is still suppressible (#9560 / php-src).
                    ++$assigned;
                    if (!$suppress) {
                        if ($outIdx >= \count($outVars)) {
                            return self::finishParse($outVars, $outIdx, $assigned, $inPos);
                        }
                        self::assignInt($outVars[$outIdx], $inPos);
                        ++$outIdx;
                    }
                    break;
                default:
                    throw new \ValueError('Bad scan conversion character "'.$spec.'"');
            }
        }

        return self::finishParse($outVars, $outIdx, $assigned, $inPos);
    }


    /**
     * php-src php_sscanf_internal — unscanned by-ref outs are still defined as null (#23567).
     *
     * @param list<Variable> $outVars
     *
     * @return array{0: int, 1: int, 2: int}
     */
    private static function finishParse(array $outVars, int $outIdx, int $assigned, int $inPos): array
    {
        $n = \count($outVars);
        for ($i = $outIdx; $i < $n; ++$i) {
            self::assignNull($outVars[$i]);
        }

        return [$assigned, $inPos, $outIdx];
    }

    /** Two-arg sscanf(): return parsed values as a list array (php-src ext/standard/sscanf.c, #4201). */
    public static function parseToArray(string $input, string $format): ?HashTable
    {
        $slots = self::countConversionSpecs($format);
        if (0 === $slots) {
            return new HashTable();
        }
        $temps = [];
        for ($i = 0; $i < $slots; ++$i) {
            $temps[] = new Variable();
        }
        [, , $stored] = self::parseWithConsumed($input, $format, $temps);
        if (0 === $stored && '' === $input) {
            return null;
        }
        $ht = new HashTable();
        for ($i = 0; $i < $slots; ++$i) {
            $copy = new Variable();
            if ($i < $stored) {
                $copy->copyFrom($temps[$i]);
            } else {
                $copy->null();
            }
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
            ++$fpos;
            $fpos = self::skipOptionalFieldWidth($format, $fpos, $len);
            if ($fpos >= $len) {
                break;
            }
            $suppress = false;
            if ('*' === $format[$fpos]) {
                $suppress = true;
                ++$fpos;
                if ($fpos >= $len) {
                    break;
                }
            }
            if ('[' === $format[$fpos]) {
                [, $fpos] = self::parseScansetMatcher($format, $fpos, $len);
                if (!$suppress) {
                    ++$count;
                }
                --$fpos;
                continue;
            }
            $spec = $format[$fpos];
            if ('%' !== $spec && !$suppress) {
                ++$count;
            }
        }

        return $count;
    }

    /** php-src ext/standard/sscanf.c — reject unsupported conversion letters before scanning (#4158). */
    public static function validateConversionCharacters(string $format): void
    {
        $len = VmString::byteLength($format);
        for ($fpos = 0; $fpos < $len; ++$fpos) {
            if ('%' !== $format[$fpos]) {
                continue;
            }
            if ($fpos + 1 >= $len) {
                break;
            }
            ++$fpos;
            $fpos = self::skipOptionalFieldWidth($format, $fpos, $len);
            if ($fpos >= $len) {
                break;
            }
            if ('*' === $format[$fpos]) {
                ++$fpos;
                if ($fpos >= $len) {
                    break;
                }
            }
            if ('[' === $format[$fpos]) {
                self::parseScansetMatcher($format, $fpos, $len);
                continue;
            }
            $spec = $format[$fpos];
            if ('%' === $spec) {
                continue;
            }
            if (!self::isSupportedConversionSpec($spec)) {
                throw new \ValueError('Bad scan conversion character "'.$spec.'"');
            }
        }
    }

    /**
     * %[scanset] — php-src ext/standard/formatted_io.c scan_set_conversion().
     *
     * @return array{0: callable(string): bool, 1: int}
     */
    private static function parseScansetMatcher(string $format, int $fpos, int $fmtLen): array
    {
        if ($fpos >= $fmtLen || '[' !== $format[$fpos]) {
            throw new \ValueError('Bad scan conversion character "["');
        }
        ++$fpos;
        $negated = false;
        if ($fpos < $fmtLen && '^' === $format[$fpos]) {
            $negated = true;
            ++$fpos;
        }
        /** @var array<string, true> $chars */
        $chars = [];
        if ($fpos >= $fmtLen) {
            throw new \ValueError('Unmatched [ in format string');
        }
        if (']' === $format[$fpos]) {
            throw new \ValueError('Unmatched [ in format string');
        }
        while ($fpos < $fmtLen && ']' !== $format[$fpos]) {
            $ch = $format[$fpos];
            if (
                $fpos + 2 < $fmtLen
                && '-' === $format[$fpos + 1]
                && ']' !== $format[$fpos + 2]
            ) {
                $lo = $ch;
                $hi = $format[$fpos + 2];
                if (ord($lo) <= ord($hi)) {
                    for ($c = ord($lo); $c <= ord($hi); ++$c) {
                        $chars[chr($c)] = true;
                    }
                    $fpos += 3;
                    continue;
                }
            }
            $chars[$ch] = true;
            ++$fpos;
        }
        if ($fpos >= $fmtLen || ']' !== $format[$fpos]) {
            throw new \ValueError('Unmatched [ in format string');
        }
        ++$fpos;
        $matcher = static function (string $ch) use ($chars, $negated): bool {
            $in = isset($chars[$ch]);

            return $negated ? !$in : $in;
        };

        return [$matcher, $fpos];
    }

    /**
     * @param callable(string): bool $matcher
     *
     * @return array{0: ?string, 1: int}
     */
    private static function scanScansetMatch(
        string $input,
        int $pos,
        int $len,
        callable $matcher,
        ?int $maxWidth = null
    ): array {
        $orig = $pos;
        $start = $pos;
        $read = 0;
        while ($pos < $len) {
            if (null !== $maxWidth && $read >= $maxWidth) {
                break;
            }
            if (!$matcher($input[$pos])) {
                break;
            }
            ++$pos;
            ++$read;
        }
        if ($start === $pos) {
            return [null, 0];
        }

        return [substr($input, $start, $pos - $start), $pos - $orig];
    }

    /**
     * Optional decimal field width after '%' (php-src ext/standard/formatted_io.c).
     *
     * @return array{0: ?int, 1: int} width (null = unlimited) and index of conversion specifier
     */
    private static function parseFieldWidth(string $format, int $fpos, int $fmtLen): array
    {
        if ($fpos >= $fmtLen || $format[$fpos] < '0' || $format[$fpos] > '9') {
            return [null, $fpos];
        }
        $width = 0;
        while ($fpos < $fmtLen && $format[$fpos] >= '0' && $format[$fpos] <= '9') {
            $width = $width * 10 + (ord($format[$fpos]) - 48);
            ++$fpos;
        }

        return [$width, $fpos];
    }

    private static function skipOptionalFieldWidth(string $format, int $fpos, int $fmtLen): int
    {
        while ($fpos < $fmtLen && $format[$fpos] >= '0' && $format[$fpos] <= '9') {
            ++$fpos;
        }

        return $fpos;
    }

    private static function isSupportedConversionSpec(string $spec): bool
    {
        return \in_array($spec, ['d', 'D', 'i', 'u', 'f', 'e', 'E', 'g', 'G', 's', 'x', 'X', 'o', 'c', 'n'], true);
    }

    /**
     * %i — signed integer with C auto-base (0 → octal, 0x → hex; php-src formatted_io.c).
     *
     * @return array{0: ?int, 1: int}
     */
    private static function scanAutoBaseInt(string $input, int $pos, int $len, ?int $maxWidth = null): array
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
        if ($pos >= $len) {
            return [null, 0];
        }
        $remaining = $len - $pos;
        if (null !== $maxWidth) {
            $remaining = min($remaining, $maxWidth);
        }
        $slice = substr($input, $pos, $remaining);
        if ('0' === $slice[0] && strlen($slice) > 1) {
            if ('x' === $slice[1] || 'X' === $slice[1]) {
                [$val, $consumed] = self::scanHex($input, $pos, $len, $maxWidth);
                if (null === $val) {
                    return [null, 0];
                }
                if ($negative) {
                    $val = -$val;
                }

                return [$val, ($pos - $orig) + $consumed];
            }

            [$val, $consumed] = self::scanOct($input, $pos, $len, $maxWidth);
            if (null === $val) {
                return [null, 0];
            }
            if ($negative) {
                $val = -$val;
            }

            return [$val, ($pos - $orig) + $consumed];
        }
        [$val, $consumed] = self::scanInt($input, $orig, $len, $maxWidth);
        if (null === $val) {
            return [null, 0];
        }

        return [$val, $consumed];
    }

    /**
     * @return array{0: ?int, 1: int}
     */
    private static function scanInt(string $input, int $pos, int $len, ?int $maxWidth = null): array
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
        $digits = '';
        $any = false;
        $digitsRead = 0;
        while ($pos < $len && $input[$pos] >= '0' && $input[$pos] <= '9') {
            if (null !== $maxWidth && $digitsRead >= $maxWidth) {
                break;
            }
            $any = true;
            $digits .= $input[$pos];
            ++$pos;
            ++$digitsRead;
        }
        if (!$any) {
            return [null, 0];
        }

        return [self::saturateDecimalInt($digits, $negative), $pos - $orig];
    }

    /**
     * Clamp scanned decimal digits to [PHP_INT_MIN, PHP_INT_MAX] (php-src formatted_io.c overflow).
     */
    private static function saturateDecimalInt(string $digits, bool $negative): int
    {
        $limit = $negative
            ? self::incrementDecimalString((string) PHP_INT_MAX)
            : (string) PHP_INT_MAX;
        $len = strlen($digits);
        $limitLen = strlen($limit);
        if ($len > $limitLen || ($len === $limitLen && $digits > $limit)) {
            return $negative ? PHP_INT_MIN : PHP_INT_MAX;
        }
        $value = (int) $digits;

        return $negative ? -$value : $value;
    }

    private static function incrementDecimalString(string $digits): string
    {
        $carry = 1;
        $out = '';
        for ($i = strlen($digits) - 1; $i >= 0; --$i) {
            $sum = (int) $digits[$i] + $carry;
            $out = (string) ($sum % 10).$out;
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $out = (string) $carry.$out;
        }

        return $out;
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private static function scanString(string $input, int $pos, int $len, ?int $maxWidth = null): array
    {
        $orig = $pos;
        $pos = self::skipSpace($input, $pos, $len);
        if ($pos >= $len) {
            return [null, 0];
        }
        $start = $pos;
        $read = 0;
        while ($pos < $len && !self::isSpace($input[$pos])) {
            if (null !== $maxWidth && $read >= $maxWidth) {
                break;
            }
            ++$pos;
            ++$read;
        }
        if ($start === $pos) {
            return [null, 0];
        }

        return [substr($input, $start, $pos - $start), $pos - $orig];
    }

    /**
     * @return array{0: ?int, 1: int}
     */
    private static function scanHex(string $input, int $pos, int $len, ?int $maxWidth = null): array
    {
        $orig = $pos;
        $pos = self::skipSpace($input, $pos, $len);
        if ($pos >= $len) {
            return [null, 0];
        }
        if (
            $pos + 1 < $len
            && '0' === $input[$pos]
            && ('x' === $input[$pos + 1] || 'X' === $input[$pos + 1])
        ) {
            $pos += 2;
        }
        $digits = '';
        $any = false;
        $digitsRead = 0;
        while ($pos < $len) {
            if (null !== $maxWidth && $digitsRead >= $maxWidth) {
                break;
            }
            $ch = $input[$pos];
            if (
                ($ch >= '0' && $ch <= '9')
                || ($ch >= 'a' && $ch <= 'f')
                || ($ch >= 'A' && $ch <= 'F')
            ) {
                $any = true;
                $digits .= $ch;
                ++$pos;
                ++$digitsRead;
                continue;
            }
            break;
        }
        if (!$any) {
            return [null, 0];
        }

        return [self::saturateHexInt($digits), $pos - $orig];
    }

    /**
     * Clamp scanned hex digits to PHP_INT_MAX (php-src formatted_io.c overflow, #15327).
     */
    private static function saturateHexInt(string $digits): int
    {
        $digits = strtolower($digits);
        $limit = dechex(\PHP_INT_MAX);
        $len = \strlen($digits);
        $limitLen = \strlen($limit);
        if ($len > $limitLen || ($len === $limitLen && $digits > $limit)) {
            return \PHP_INT_MAX;
        }
        $value = 0;
        for ($i = 0; $i < $len; ++$i) {
            $ch = $digits[$i];
            $digit = $ch >= '0' && $ch <= '9'
                ? \ord($ch) - 48
                : \ord($ch) - 87;
            $value = ($value << 4) + $digit;
        }

        return $value;
    }

    /**
     * @return array{0: ?int, 1: int}
     */
    private static function scanOct(string $input, int $pos, int $len, ?int $maxWidth = null): array
    {
        $orig = $pos;
        $pos = self::skipSpace($input, $pos, $len);
        if ($pos >= $len) {
            return [null, 0];
        }
        $value = 0;
        $any = false;
        $digitsRead = 0;
        while ($pos < $len && $input[$pos] >= '0' && $input[$pos] <= '7') {
            if (null !== $maxWidth && $digitsRead >= $maxWidth) {
                break;
            }
            $any = true;
            $value = ($value << 3) + (ord($input[$pos]) - 48);
            ++$pos;
            ++$digitsRead;
        }
        if (!$any) {
            return [null, 0];
        }

        return [$value, $pos - $orig];
    }

    /**
     * @return array{0: ?int, 1: int, 2: ?string}
     */
    private static function scanUnsigned(string $input, int $pos, int $len, ?int $maxWidth = null): array
    {
        $orig = $pos;
        $pos = self::skipSpace($input, $pos, $len);
        if ($pos >= $len) {
            return [null, 0, null];
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
        $digitsRead = 0;
        while ($pos < $len && $input[$pos] >= '0' && $input[$pos] <= '9') {
            if (null !== $maxWidth && $digitsRead >= $maxWidth) {
                break;
            }
            $any = true;
            $value = $value * 10 + (ord($input[$pos]) - 48);
            ++$pos;
            ++$digitsRead;
        }
        if (!$any) {
            return [null, 0, null];
        }
        if ($negative) {
            return [null, $pos - $orig, self::unsignedWrapDecimal($value)];
        }

        return [$value, $pos - $orig, null];
    }

    /**
     * @return array{0: ?string, 1: int}
     */
    private static function scanChar(string $input, int $pos, int $len, ?int $maxWidth = null): array
    {
        if ($pos >= $len) {
            return [null, 0];
        }
        $width = $maxWidth ?? 1;
        if ($width <= 0) {
            return [null, 0];
        }
        if (1 === $width) {
            $ch = $input[$pos];
            $value = self::isSpace($ch) ? '' : $ch;

            return [$value, 1];
        }
        $end = min($pos + $width, $len);
        $value = substr($input, $pos, $end - $pos);

        return [$value, $end - $pos];
    }

    /**
     * @return array{0: ?float, 1: int}
     */
    private static function scanFloat(string $input, int $pos, int $len, ?int $maxWidth = null, bool $allowExponent = false): array
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
        $read = 0;
        while ($pos < $len && $input[$pos] >= '0' && $input[$pos] <= '9') {
            if (null !== $maxWidth && $read >= $maxWidth) {
                break;
            }
            $any = true;
            ++$pos;
            ++$read;
        }
        if ($pos < $len && '.' === $input[$pos]) {
            if (null === $maxWidth || $read < $maxWidth) {
                ++$pos;
                ++$read;
                while ($pos < $len && $input[$pos] >= '0' && $input[$pos] <= '9') {
                    if (null !== $maxWidth && $read >= $maxWidth) {
                        break;
                    }
                    $any = true;
                    ++$pos;
                    ++$read;
                }
            }
        }
        if ($allowExponent && $pos < $len && ('e' === $input[$pos] || 'E' === $input[$pos])) {
            if (null === $maxWidth || $read < $maxWidth) {
                $expPos = $pos;
                ++$pos;
                ++$read;
                if ($pos < $len && ('+' === $input[$pos] || '-' === $input[$pos])) {
                    if (null !== $maxWidth && $read >= $maxWidth) {
                        $pos = $expPos;
                    } else {
                        ++$pos;
                        ++$read;
                    }
                }
                $expDigits = false;
                while ($pos < $len && $input[$pos] >= '0' && $input[$pos] <= '9') {
                    if (null !== $maxWidth && $read >= $maxWidth) {
                        break;
                    }
                    $expDigits = true;
                    $any = true;
                    ++$pos;
                    ++$read;
                }
                if (!$expDigits) {
                    $pos = $expPos;
                }
            }
        }
        if (!$any) {
            return [null, 0];
        }
        $sliceLen = null !== $maxWidth ? min($pos - $start, $maxWidth) : $pos - $start;
        $slice = substr($input, $start, $sliceLen);

        return [(float) $slice, ($start - $orig) + $sliceLen];
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

    /** 2^64 − $magnitude as decimal string (php-src %u negative input, ext/standard/sscanf.c). */
    private static function unsignedWrapDecimal(int $magnitude): string
    {
        return self::subtractDecimalStrings('18446744073709551616', (string) $magnitude);
    }

    private static function subtractDecimalStrings(string $minuend, string $subtrahend): string
    {
        $aDigits = \str_split($minuend);
        // Left-pad without \str_pad — NestedJIT would emit unresolved __compiler_str_pad (#27663).
        $width = \strlen($minuend);
        $padded = $subtrahend;
        while (\strlen($padded) < $width) {
            $padded = '0'.$padded;
        }
        $bDigits = \str_split($padded);
        $borrow = 0;
        for ($i = \count($aDigits) - 1; $i >= 0; --$i) {
            $d = (int) $aDigits[$i] - (int) $bDigits[$i] - $borrow;
            if ($d < 0) {
                $d += 10;
                $borrow = 1;
            } else {
                $borrow = 0;
            }
            $aDigits[$i] = (string) $d;
        }
        $result = \ltrim(\implode('', $aDigits), '0');

        return '' === $result ? '0' : $result;
    }

    private static function assignNull(Variable $dest): void
    {
        $tmp = new Variable();
        $tmp->null();
        // resolveIndirect: NestedJIT-bridged; byRefTarget was not (#27663 / peer #24117).
        $dest->resolveIndirect()->copyFrom($tmp);
    }

    private static function assignInt(Variable $dest, int $value): void
    {
        $tmp = new Variable();
        $tmp->int($value);
        $dest->resolveIndirect()->copyFrom($tmp);
    }

    private static function assignString(Variable $dest, string $value): void
    {
        $tmp = new Variable();
        $tmp->string($value);
        $dest->resolveIndirect()->copyFrom($tmp);
    }

    private static function assignFloat(Variable $dest, float $value): void
    {
        $tmp = new Variable();
        $tmp->float($value);
        $dest->resolveIndirect()->copyFrom($tmp);
    }
}
