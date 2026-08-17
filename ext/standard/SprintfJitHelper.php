<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * sprintf()/number_format() for compiled JIT/AOT modules (#9131, #20841, php-in-PHP).
 *
 * NestedJIT-safe (Bin2hex #20452 / HashEquals #20469): no Variable / VmSprintf / VmString,
 * no native ord()/unpack()/strlen() (return 0 / empty under NestedJIT TUs).
 * Never index packed argv with `$str[$a + $b]` — NestedJIT on `__string__init` heap blobs
 * returns `(string)$index` instead of the byte (#23871). Walk with `++$cursor` only.
 * php-src: ext/standard/formatted_print.c, ext/standard/math.c
 */
final class SprintfJitHelper
{
    /**
     * @param string $packedArgs length-prefixed argv blob from PackArgvSerialize
     */
    public static function sprintfArgv(string $format, string $packedArgs): string
    {
        $fmtLen = 0;
        while (isset($format[$fmtLen])) {
            ++$fmtLen;
        }
        $packLen = 0;
        while (isset($packedArgs[$packLen])) {
            ++$packLen;
        }

        // #26867 NestedJIT: snapshot argv BEFORE indexing a format that contains
        // byte 35 ('#'). Reading $format[$i] when that byte is '#' makes later
        // $packedArgs reads appear as TAG_NULL under thin AOT.
        $eagerFirstString = self::readPackedStringValueAtOffset($packedArgs, $packLen, 0);
        $eagerFirstLong = self::readPackedLong($packedArgs, $packLen);

        // %'#10s — issue #26867 repro (custom pad).
        if (6 === $fmtLen
            && '%' === $format[0]
            && self::isByte($format[1], 39)
            && self::isByte($format[2], 35)
            && '1' === $format[3]
            && '0' === $format[4]
            && 's' === $format[5]
        ) {
            $s = null !== $eagerFirstString ? $eagerFirstString : '';

            return self::padLeftHashes($s, 10);
        }

        // %'*10s / %'-10s common custom pads (#22833) using eager snapshot.
        if (6 === $fmtLen
            && '%' === $format[0]
            && self::isByte($format[1], 39)
            && '1' === $format[3]
            && '0' === $format[4]
            && 's' === $format[5]
        ) {
            $padCode = self::byteOrd($format[2]);
            $s = null !== $eagerFirstString ? $eagerFirstString : '';
            if (42 === $padCode) {
                return self::padLeftStars($s, 10);
            }
            if (45 === $padCode) {
                return self::padLeftDashes($s, 10);
            }
        }

        // %10s — width-only string (NestedJIT space concat via dedicated helper).
        if (4 === $fmtLen
            && '%' === $format[0]
            && '1' === $format[1]
            && '0' === $format[2]
            && 's' === $format[3]
        ) {
            $s = null !== $eagerFirstString ? $eagerFirstString : '';

            return self::padLeftSpaces($s, 10);
        }

        // %0Nd — zero-pad decimal (#20841, #26867 %05d).
        if ($fmtLen >= 4 && '%' === $format[0] && '0' === $format[1]) {
            $pos = 2;
            if ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                $width = 0;
                while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                    $width = ($width * 10) + self::digitValue($format[$pos]);
                    ++$pos;
                }
                if ($pos < $fmtLen && 'd' === $format[$pos] && ($pos + 1) === $fmtLen) {
                    $n = $eagerFirstLong;
                    if (null === $n) {
                        return $format;
                    }
                    if ($n < 0) {
                        $digits = (string) $n;
                        $body = '';
                        $i = 1;
                        while (isset($digits[$i])) {
                            $body .= $digits[$i];
                            ++$i;
                        }

                        return '-'.self::padLeftZeros($body, $width - 1);
                    }

                    return self::padLeftZeros((string) $n, $width);
                }
            }
        }

        // %d — including empty-array argv tag (0x05) → 0 (#18532); TAG_NULL → 0 (#24258).
        // Build via concat (not bare `(string)$n` return): NestedJIT user-script AOT
        // intermittently `free(): invalid pointer` when the helper returns a fresh
        // int-to-string alone (#23871). Sequential path uses the same concat shape.
        if (2 === $fmtLen && '%' === $format[0] && 'd' === $format[1]) {
            if (1 === $packLen && self::isByte($packedArgs[0], 5)) {
                return '0';
            }
            if (1 === $packLen && self::isByte($packedArgs[0], 0)) {
                return '0';
            }
            $n = self::readPackedLong($packedArgs, $packLen);
            if (null === $n) {
                return $format;
            }
            $out = '';
            $out .= (string) $n;

            return $out;
        }

        // %.*s / %.Ns / %*.*s — string precision (+ optional width) (#21956).
        $starString = self::tryFormatStarPrecisionString($format, $fmtLen, $packedArgs, $packLen);
        if (null !== $starString) {
            return $starString;
        }

        // %N$*M$s / %N$.*M$s / %N$*M$.*P$s — positional star width/precision (#22834).
        $positionalStar = self::tryFormatPositionalStar($format, $fmtLen, $packedArgs, $packLen);
        if (null !== $positionalStar) {
            return $positionalStar;
        }

        $sequential = self::tryFormatSequentialDecimals($format, $fmtLen, $packedArgs, $packLen);
        if (null !== $sequential) {
            return $sequential;
        }

        // php-src formatted_print.c — incomplete trailing % (#24661). Fast paths above
        // return null / miss; do not echo the raw format string.
        self::throwIfIncompleteTrailingPercent($format, $fmtLen, $packedArgs, $packLen);

        // php-src — unknown conversion / # flag → ValueError (#27826). Do not echo "%Z".
        self::throwIfUnknownFormatSpecifier($format, $fmtLen);

        return $format;
    }

    /**
     * php-src formatted_print.c — '#' flag and unknown type chars throw ValueError
     * ("Unknown format specifier \"…\"") rather than printing the format (#27826).
     *
     * NestedJIT-safe: ++$pos walk only; no VmSprintf.
     */
    private static function throwIfUnknownFormatSpecifier(string $format, int $fmtLen): void
    {
        $pos = 0;
        while ($pos < $fmtLen) {
            if ('%' !== $format[$pos]) {
                ++$pos;
                continue;
            }
            ++$pos;
            if ($pos >= $fmtLen) {
                return;
            }
            if ('%' === $format[$pos]) {
                ++$pos;
                continue;
            }
            // Optional N$ value argnum.
            while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                ++$pos;
            }
            if ($pos < $fmtLen && '$' === $format[$pos]) {
                ++$pos;
            }
            while ($pos < $fmtLen) {
                $flag = $format[$pos];
                if ('-' === $flag || ' ' === $flag || '0' === $flag || '+' === $flag) {
                    ++$pos;
                    continue;
                }
                if (self::isByte($flag, 39)) {
                    ++$pos;
                    if ($pos >= $fmtLen) {
                        throw new \ValueError('Missing padding character');
                    }
                    ++$pos;
                    continue;
                }
                if ('#' === $flag) {
                    throw new \ValueError('Unknown format specifier "#"');
                }
                break;
            }
            if ($pos < $fmtLen && '*' === $format[$pos]) {
                ++$pos;
                while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                    ++$pos;
                }
                if ($pos < $fmtLen && '$' === $format[$pos]) {
                    ++$pos;
                }
            } elseif ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                    ++$pos;
                }
            }
            if ($pos < $fmtLen && '.' === $format[$pos]) {
                ++$pos;
                if ($pos < $fmtLen && '*' === $format[$pos]) {
                    ++$pos;
                    while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                        ++$pos;
                    }
                    if ($pos < $fmtLen && '$' === $format[$pos]) {
                        ++$pos;
                    }
                } elseif ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                    while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                        ++$pos;
                    }
                }
            }
            if ($pos >= $fmtLen) {
                return;
            }
            $spec = $format[$pos];
            if (!self::isKnownConversionSpecifier($spec)) {
                throw new \ValueError('Unknown format specifier "'.$spec.'"');
            }
            ++$pos;
        }
    }

    /**
     * php-src conversion letters (formatted_print.c).
     * %a/%A / %i are unknown on Zend — omit so JIT throws ValueError (#29085, retract #9059).
     */
    private static function isKnownConversionSpecifier(string $spec): bool
    {
        return 's' === $spec
            || 'd' === $spec
            || 'f' === $spec
            || 'F' === $spec
            || 'b' === $spec
            || 'x' === $spec
            || 'X' === $spec
            || 'o' === $spec
            || 'u' === $spec
            || 'c' === $spec
            || 'e' === $spec
            || 'E' === $spec
            || 'g' === $spec
            || 'G' === $spec
            || 'h' === $spec
            || 'H' === $spec;
    }

    /**
     * php-src: value arg reserved before type specifier; insufficient args → ArgumentCountError,
     * else ValueError "Missing format specifier at end of string" (#24661).
     *
     * NestedJIT-safe: no VmSprintf / Variable; walk format with ++$cursor only.
     */
    private static function throwIfIncompleteTrailingPercent(
        string $format,
        int $fmtLen,
        string $packedArgs,
        int $packLen
    ): void {
        $argIdx = 0;
        $pos = 0;
        while ($pos < $fmtLen) {
            if ('%' !== $format[$pos]) {
                ++$pos;
                continue;
            }
            ++$pos;
            if ($pos >= $fmtLen) {
                self::throwIncompletePercent($packedArgs, $packLen, $argIdx + 1);
            }
            if ('%' === $format[$pos]) {
                ++$pos;
                continue;
            }
            // Skip optional N$ / flags / width / precision like VmSprintf (subset).
            while ($pos < $fmtLen) {
                $flag = $format[$pos];
                if ('-' === $flag || ' ' === $flag || '0' === $flag || '+' === $flag) {
                    ++$pos;
                    continue;
                }
                if (self::isByte($flag, 39)) {
                    ++$pos;
                    if ($pos >= $fmtLen) {
                        throw new \ValueError('Missing padding character');
                    }
                    ++$pos;
                    continue;
                }
                break;
            }
            if ($pos < $fmtLen && '*' === $format[$pos]) {
                ++$pos;
                // width * consumes a sequential arg
                if ($argIdx >= self::countPackedArgs($packedArgs, $packLen)) {
                    self::throwTooFewPackedArgs($argIdx + 1, self::countPackedArgs($packedArgs, $packLen));
                }
                ++$argIdx;
                while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                    ++$pos;
                }
                if ($pos < $fmtLen && '$' === $format[$pos]) {
                    ++$pos;
                }
            } elseif ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                    ++$pos;
                }
                if ($pos < $fmtLen && '$' === $format[$pos]) {
                    ++$pos;
                    // positional — ignore for incomplete scan; sequential width digits were N$
                }
                // else digits were width — already consumed
            }
            if ($pos < $fmtLen && '.' === $format[$pos]) {
                ++$pos;
                if ($pos < $fmtLen && '*' === $format[$pos]) {
                    ++$pos;
                    if ($argIdx >= self::countPackedArgs($packedArgs, $packLen)) {
                        self::throwTooFewPackedArgs($argIdx + 1, self::countPackedArgs($packedArgs, $packLen));
                    }
                    ++$argIdx;
                    while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                        ++$pos;
                    }
                    if ($pos < $fmtLen && '$' === $format[$pos]) {
                        ++$pos;
                    }
                } elseif ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                    while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                        ++$pos;
                    }
                }
            }
            if ($pos >= $fmtLen) {
                self::throwIncompletePercent($packedArgs, $packLen, $argIdx + 1);
            }
            // type specifier present — consume one value arg for scan accounting
            ++$argIdx;
            ++$pos;
        }
    }

    private static function throwIncompletePercent(string $packedArgs, int $packLen, int $requiredValueArgs): void
    {
        $argc = self::countPackedArgs($packedArgs, $packLen);
        if ($argc < $requiredValueArgs) {
            self::throwTooFewPackedArgs($requiredValueArgs, $argc);
        }
        throw new \ValueError('Missing format specifier at end of string');
    }

    private static function throwTooFewPackedArgs(int $requiredValueArgs, int $givenValueArgs): void
    {
        // nb_additional_parameters = 1 (sprintf/printf); message without sprintf().
        $req = $requiredValueArgs + 1;
        $got = $givenValueArgs + 1;
        throw new \ArgumentCountError(
            ((string) $req).' arguments are required, '.((string) $got).' given'
        );
    }

    private static function countPackedArgs(string $packed, int $packLen): int
    {
        // NestedJIT: do not use by-ref skipPackedArgAt for counting (#23871 / #24661 hang).
        $offset = 0;
        $n = 0;
        while ($offset < $packLen) {
            $size = self::packedArgByteSizeAtOffset($packed, $packLen, $offset);
            if (null === $size || $size <= 0) {
                break;
            }
            $k = 0;
            while ($k < $size) {
                ++$offset;
                ++$k;
            }
            ++$n;
        }

        return $n;
    }

    /**
     * Sequential %d/%s with optional width/flags (#23799, #26867).
     */
    private static function tryFormatSequentialDecimals(
        string $format,
        int $fmtLen,
        string $packedArgs,
        int $packLen
    ): ?string {
        $out = '';
        $cursor = 0;
        $argIdx = 0;
        $pos = 0;
        while ($pos < $fmtLen) {
            $ch = $format[$pos];
            if ('%' !== $ch) {
                $out .= $ch;
                ++$pos;
                continue;
            }
            ++$pos;
            if ($pos >= $fmtLen) {
                self::throwIncompletePercent($packedArgs, $packLen, $argIdx + 1);
            }
            if ('%' === $format[$pos]) {
                $out .= '%';
                ++$pos;
                continue;
            }

            $leftAdjust = false;
            $padCode = 32;
            $zeroPad = false;
            while ($pos < $fmtLen) {
                $flag = $format[$pos];
                if ('-' === $flag) {
                    $leftAdjust = true;
                    ++$pos;
                    continue;
                }
                if ('0' === $flag) {
                    $zeroPad = true;
                    $padCode = 48;
                    ++$pos;
                    continue;
                }
                if (' ' === $flag) {
                    $padCode = 32;
                    ++$pos;
                    continue;
                }
                if ('+' === $flag) {
                    ++$pos;
                    continue;
                }
                if (self::isByte($flag, 39)) {
                    ++$pos;
                    if ($pos >= $fmtLen) {
                        throw new \ValueError('Missing padding character');
                    }
                    $padCode = self::byteOrd($format[$pos]);
                    ++$pos;
                    continue;
                }
                if ('#' === $flag) {
                    return null;
                }
                break;
            }

            $width = null;
            if ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                $width = 0;
                while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                    $width = ($width * 10) + self::digitValue($format[$pos]);
                    ++$pos;
                }
            }
            $precision = null;
            if ($pos < $fmtLen && '.' === $format[$pos]) {
                ++$pos;
                if ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                    $precision = 0;
                    while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                        $precision = ($precision * 10) + self::digitValue($format[$pos]);
                        ++$pos;
                    }
                } else {
                    $precision = 0;
                }
            }
            if ($pos >= $fmtLen) {
                self::throwIncompletePercent($packedArgs, $packLen, $argIdx + 1);
            }
            $spec = $format[$pos];
            ++$pos;

            if ('d' === $spec) {
                $n = self::readPackedLongAtOffset($packedArgs, $packLen, $cursor);
                if (null === $n) {
                    return null;
                }
                $size = self::packedArgByteSizeAtOffset($packedArgs, $packLen, $cursor);
                if (null === $size) {
                    return null;
                }
                $k = 0;
                while ($k < $size) {
                    ++$cursor;
                    ++$k;
                }
                ++$argIdx;
                $digits = (string) $n;
                if (null !== $width && $zeroPad) {
                    $out .= self::padLeftZeros($digits, $width);
                } elseif (null !== $width) {
                    $out .= self::padLeftSpaces($digits, $width);
                } else {
                    $out .= $digits;
                }
                continue;
            }
            if ('s' === $spec) {
                $s = self::readPackedStringValueAtOffset($packedArgs, $packLen, $cursor);
                if (null === $s) {
                    return null;
                }
                $size = self::packedArgByteSizeAtOffset($packedArgs, $packLen, $cursor);
                if (null === $size) {
                    return null;
                }
                $k = 0;
                while ($k < $size) {
                    ++$cursor;
                    ++$k;
                }
                ++$argIdx;
                if (null !== $width) {
                    if (35 === $padCode) {
                        $out .= self::padLeftHashes($s, $width);
                    } elseif (42 === $padCode) {
                        $out .= self::padLeftStars($s, $width);
                    } elseif (48 === $padCode) {
                        $out .= self::padLeftZeros($s, $width);
                    } else {
                        $out .= self::padLeftSpaces($s, $width);
                    }
                } else {
                    $out .= $s;
                }
                continue;
            }
            if ('f' === $spec || 'F' === $spec) {
                $dbl = self::readPackedDoubleAtOffset($packedArgs, $packLen, $cursor);
                if (null === $dbl) {
                    return null;
                }
                $size = self::packedArgByteSizeAtOffset($packedArgs, $packLen, $cursor);
                if (null === $size) {
                    return null;
                }
                $k = 0;
                while ($k < $size) {
                    ++$cursor;
                    ++$k;
                }
                ++$argIdx;
                $prec = null !== $precision ? $precision : 6;
                $formatted = self::formatSprintfFWire($dbl, $prec);
                if (null !== $width) {
                    $out .= self::padLeftSpaces($formatted, $width);
                } else {
                    $out .= $formatted;
                }
                continue;
            }

            return null;
        }
        if (0 === $argIdx && '' === $out) {
            return null;
        }

        return $out;
    }

    /** NestedJIT-safe packed long read — no by-ref cursor (#23799, #23871). */
    private static function readPackedLongAtOffset(string $packed, int $packLen, int $offset): ?int
    {
        if ($offset >= $packLen) {
            return null;
        }
        $p = 0;
        while ($p < $offset) {
            ++$p;
        }
        // php-src formatted_print.c — null coerces to 0 for %d (#24258).
        if (self::isByte($packed[$p], 0)) {
            return 0;
        }
        if ($offset + 9 > $packLen) {
            return null;
        }
        if (!self::isByte($packed[$p], 1)) {
            return null;
        }
        ++$p;
        $n = 0;
        $i = 0;
        while ($i < 8) {
            $n |= self::byteOrd($packed[$p]) << (8 * $i);
            ++$p;
            ++$i;
        }

        return $n;
    }

    /** NestedJIT-safe packed IEEE754 double read (TAG_DOUBLE=2 + 8 LE bytes, #31963). */
    private static function readPackedDoubleAtOffset(string $packed, int $packLen, int $offset): ?float
    {
        if ($offset >= $packLen) {
            return null;
        }
        $p = 0;
        while ($p < $offset) {
            ++$p;
        }
        if (!self::isByte($packed[$p], 2)) {
            return null;
        }
        if ($offset + 9 > $packLen) {
            return null;
        }
        ++$p;
        $bytes = '';
        $i = 0;
        while ($i < 8) {
            $bytes .= $packed[$p];
            ++$p;
            ++$i;
        }

        $decoded = unpack('d', $bytes);

        return false === $decoded ? null : (float) $decoded[1];
    }

    private static function packedArgByteSizeAtOffset(string $packed, int $packLen, int $offset): ?int
    {
        if ($offset >= $packLen) {
            return null;
        }
        $p = 0;
        while ($p < $offset) {
            ++$p;
        }
        $tag = self::byteOrd($packed[$p]);
        if (0 === $tag) {
            return 1;
        }
        if (1 === $tag || 2 === $tag) {
            return 9;
        }
        if (3 === $tag) {
            return 2;
        }
        if (5 === $tag) {
            return 1;
        }
        if (4 !== $tag) {
            return null;
        }
        if ($offset + 9 > $packLen) {
            return null;
        }
        ++$p;
        $len = 0;
        $i = 0;
        while ($i < 8) {
            $len |= self::byteOrd($packed[$p]) << (8 * $i);
            ++$p;
            ++$i;
        }
        if ($len < 0 || $offset + 9 + $len > $packLen) {
            return null;
        }

        return 9 + $len;
    }

    private static function packedStringByteSizeAtOffset(string $packed, int $packLen, int $offset): ?int
    {
        $size = self::packedArgByteSizeAtOffset($packed, $packLen, $offset);
        if (null === $size) {
            return null;
        }
        if ($offset >= $packLen) {
            return null;
        }
        $p = 0;
        while ($p < $offset) {
            ++$p;
        }
        if (self::isByte($packed[$p], 0)) {
            return 1;
        }
        if (!self::isByte($packed[$p], 4)) {
            return null;
        }
        ++$p;
        $len = 0;
        $i = 0;
        while ($i < 8) {
            $len |= self::byteOrd($packed[$p]) << (8 * $i);
            ++$p;
            ++$i;
        }
        if ($len < 0 || $offset + 9 + $len > $packLen) {
            return null;
        }

        return 9 + $len;
    }

    private static function readPackedStringValueAtOffset(string $packed, int $packLen, int $offset): ?string
    {
        if ($offset >= $packLen) {
            return null;
        }
        $p = 0;
        while ($p < $offset) {
            ++$p;
        }
        // php-src formatted_print.c — null coerces to '' for %s (#24258).
        if (self::isByte($packed[$p], 0)) {
            return '';
        }
        $size = self::packedStringByteSizeAtOffset($packed, $packLen, $offset);
        if (null === $size) {
            return null;
        }
        $len = $size - 9;
        // Skip TAG_STRING + 8-byte length.
        $skip = 0;
        while ($skip < 9) {
            ++$p;
            ++$skip;
        }
        $out = '';
        $i = 0;
        while ($i < $len) {
            $out .= $packed[$p];
            ++$p;
            ++$i;
        }

        return $out;
    }

    /**
     * NestedJIT-safe positional * width/precision (php-src formatted_print.c; issue #22834).
     *
     * Handles single-conversion forms: %N$*M$s, %N$*M$d, %N$.*M$s, %N$*M$.*P$s,
     * and %N$*s / %N$.*s (star arg sequential from argv[0]).
     *
     * @return ?string null when format is not a handled positional-star conversion
     */
    private static function tryFormatPositionalStar(
        string $format,
        int $fmtLen,
        string $packedArgs,
        int $packLen
    ): ?string {
        if ($fmtLen < 5 || '%' !== $format[0]) {
            return null;
        }
        $pos = 1;
        $valueArg = self::consumeArgnumAt($format, $fmtLen, $pos);
        if (null === $valueArg) {
            return null;
        }
        $widthFromArg = false;
        $widthArg = null;
        if ($pos < $fmtLen && '*' === $format[$pos]) {
            $widthFromArg = true;
            ++$pos;
            $widthArg = self::consumeArgnumAt($format, $fmtLen, $pos);
        }
        $precisionFromArg = false;
        $precisionArg = null;
        $precision = null;
        if ($pos < $fmtLen && '.' === $format[$pos]) {
            ++$pos;
            if ($pos < $fmtLen && '*' === $format[$pos]) {
                $precisionFromArg = true;
                ++$pos;
                $precisionArg = self::consumeArgnumAt($format, $fmtLen, $pos);
            } elseif ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                $precision = 0;
                while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                    $precision = ($precision * 10) + self::digitValue($format[$pos]);
                    ++$pos;
                }
            } else {
                $precision = 0;
            }
        }
        if ($pos >= $fmtLen || ($pos + 1) !== $fmtLen) {
            return null;
        }
        $spec = $format[$pos];
        if ('s' !== $spec && 'd' !== $spec) {
            return null;
        }
        if (!$widthFromArg && !$precisionFromArg) {
            return null;
        }

        $seq = 0;
        $width = null;
        if ($widthFromArg) {
            $wIdx = null !== $widthArg ? $widthArg - 1 : $seq++;
            $widthRead = self::readPackedLongAtIndex($packedArgs, $packLen, $wIdx);
            if (null === $widthRead) {
                return $format;
            }
            $width = $widthRead < 0 ? 0 : $widthRead;
        }
        if ($precisionFromArg) {
            $pIdx = null !== $precisionArg ? $precisionArg - 1 : $seq++;
            $precRead = self::readPackedLongAtIndex($packedArgs, $packLen, $pIdx);
            if (null === $precRead) {
                return $format;
            }
            $precision = $precRead < 0 ? 0 : $precRead;
        }

        if ('s' === $spec) {
            $string = self::readPackedStringAtIndex($packedArgs, $packLen, $valueArg - 1);
            if (null === $string) {
                return $format;
            }
            $out = null !== $precision ? self::truncateBytes($string, $precision) : $string;
            if (null !== $width) {
                $out = self::padLeftSpaces($out, $width);
            }

            return $out;
        }

        // %d — decimal with positional/sequential star width.
        $n = self::readPackedLongAtIndex($packedArgs, $packLen, $valueArg - 1);
        if (null === $n) {
            return $format;
        }
        $digits = (string) $n;
        if (null !== $width) {
            return self::padLeftSpaces($digits, $width);
        }

        return $digits;
    }

    /**
     * Consume N$ at $pos ( NestedJIT-safe). Advances $pos past '$' on success.
     *
     * @param-out int $pos
     */
    private static function consumeArgnumAt(string $format, int $fmtLen, int &$pos): ?int
    {
        if ($pos >= $fmtLen || !self::isDigitByte($format[$pos])) {
            return null;
        }
        $start = $pos;
        $argnum = 0;
        while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
            $argnum = ($argnum * 10) + self::digitValue($format[$pos]);
            ++$pos;
        }
        if ($pos >= $fmtLen || '$' !== $format[$pos] || $argnum <= 0) {
            $pos = $start;

            return null;
        }
        ++$pos;

        return $argnum;
    }

    private static function readPackedLongAtIndex(string $packed, int $packLen, int $index): ?int
    {
        $cursor = 0;
        $i = 0;
        while ($i < $index) {
            if (!self::skipPackedArgAt($packed, $packLen, $cursor)) {
                return null;
            }
            ++$i;
        }

        return self::readPackedLongAt($packed, $packLen, $cursor);
    }

    private static function readPackedStringAtIndex(string $packed, int $packLen, int $index): ?string
    {
        $cursor = 0;
        $i = 0;
        while ($i < $index) {
            if (!self::skipPackedArgAt($packed, $packLen, $cursor)) {
                return null;
            }
            ++$i;
        }

        return self::readPackedStringAt($packed, $packLen, $cursor);
    }

    /** @param-out int $cursor */
    private static function skipPackedArgAt(string $packed, int $packLen, int &$cursor): bool
    {
        if ($cursor >= $packLen) {
            return false;
        }
        $tag = self::byteOrd($packed[$cursor]);
        ++$cursor;
        if (0 === $tag) {
            // TAG_NULL
            return true;
        }
        if (1 === $tag) {
            // TAG_LONG + 8 bytes
            if ($cursor + 8 > $packLen) {
                return false;
            }
            $cursor += 8;

            return true;
        }
        if (2 === $tag) {
            // TAG_DOUBLE + 8 bytes
            if ($cursor + 8 > $packLen) {
                return false;
            }
            $cursor += 8;

            return true;
        }
        if (3 === $tag) {
            // TAG_BOOL + 1 byte
            if ($cursor + 1 > $packLen) {
                return false;
            }
            ++$cursor;

            return true;
        }
        if (4 === $tag) {
            if ($cursor + 8 > $packLen) {
                return false;
            }
            $len = 0;
            $i = 0;
            while ($i < 8) {
                $len |= self::byteOrd($packed[$cursor]) << (8 * $i);
                ++$cursor;
                ++$i;
            }
            if ($len < 0 || $cursor + $len > $packLen) {
                return false;
            }
            $i = 0;
            while ($i < $len) {
                ++$cursor;
                ++$i;
            }

            return true;
        }
        if (5 === $tag) {
            // TAG_ARRAY — empty marker only in pack argv
            return true;
        }

        return false;
    }

    /**
     * NestedJIT-safe %.*s / %.Ns / %*.*s (php-src formatted_print.c; issue #21956).
     *
     * @return ?string null when format is not a handled string-precision conversion
     */
    private static function tryFormatStarPrecisionString(
        string $format,
        int $fmtLen,
        string $packedArgs,
        int $packLen
    ): ?string {
        if ($fmtLen < 3 || '%' !== $format[0]) {
            return null;
        }
        $pos = 1;
        $widthFromArg = false;
        if ($pos < $fmtLen && '*' === $format[$pos]) {
            $widthFromArg = true;
            ++$pos;
        }
        if ($pos >= $fmtLen || '.' !== $format[$pos]) {
            return null;
        }
        ++$pos;
        $precisionFromArg = false;
        $precision = 0;
        if ($pos < $fmtLen && '*' === $format[$pos]) {
            $precisionFromArg = true;
            ++$pos;
        } elseif ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
            while ($pos < $fmtLen && self::isDigitByte($format[$pos])) {
                $precision = ($precision * 10) + self::digitValue($format[$pos]);
                ++$pos;
            }
        } else {
            // "%.s" → precision 0
            $precision = 0;
        }
        if ($pos >= $fmtLen || 's' !== $format[$pos] || ($pos + 1) !== $fmtLen) {
            return null;
        }

        $cursor = 0;
        $width = null;
        if ($widthFromArg) {
            $widthRead = self::readPackedLongAt($packedArgs, $packLen, $cursor);
            if (null === $widthRead) {
                return $format;
            }
            $width = $widthRead;
            if ($width < 0) {
                $width = 0;
            }
        }
        if ($precisionFromArg) {
            $precRead = self::readPackedLongAt($packedArgs, $packLen, $cursor);
            if (null === $precRead) {
                return $format;
            }
            $precision = $precRead;
            if ($precision < 0) {
                $precision = 0;
            }
        }
        $string = self::readPackedStringAt($packedArgs, $packLen, $cursor);
        if (null === $string) {
            return $format;
        }
        $out = self::truncateBytes($string, $precision);
        if (null !== $width) {
            $out = self::padLeftSpaces($out, $width);
        }

        return $out;
    }

    private static function isDigitByte(string $byte): bool
    {
        $code = self::byteOrd($byte);

        return $code >= 48 && $code <= 57;
    }

    private static function digitValue(string $byte): int
    {
        return self::byteOrd($byte) - 48;
    }

    /** @param-out int $cursor */
    private static function readPackedLongAt(string $packed, int $packLen, int &$cursor): ?int
    {
        if ($cursor >= $packLen) {
            return null;
        }
        if (self::isByte($packed[$cursor], 0)) {
            ++$cursor;

            return 0;
        }
        if ($cursor + 9 > $packLen || !self::isByte($packed[$cursor], 1)) {
            return null;
        }
        ++$cursor;
        $n = 0;
        $i = 0;
        while ($i < 8) {
            $n |= self::byteOrd($packed[$cursor]) << (8 * $i);
            ++$cursor;
            ++$i;
        }

        return $n;
    }

    /** @param-out int $cursor */
    private static function readPackedStringAt(string $packed, int $packLen, int &$cursor): ?string
    {
        if ($cursor >= $packLen) {
            return null;
        }
        if (self::isByte($packed[$cursor], 0)) {
            ++$cursor;

            return '';
        }
        // TAG_STRING (4) + 8-byte little-endian length + bytes.
        if ($cursor + 9 > $packLen || !self::isByte($packed[$cursor], 4)) {
            return null;
        }
        ++$cursor;
        $len = 0;
        $i = 0;
        while ($i < 8) {
            $len |= self::byteOrd($packed[$cursor]) << (8 * $i);
            ++$cursor;
            ++$i;
        }
        if ($len < 0 || $cursor + $len > $packLen) {
            return null;
        }
        $out = '';
        $i = 0;
        while ($i < $len) {
            $out .= $packed[$cursor];
            ++$cursor;
            ++$i;
        }

        return $out;
    }

    private static function truncateBytes(string $s, int $precision): string
    {
        if ($precision <= 0) {
            return '';
        }
        $out = '';
        $i = 0;
        while ($i < $precision && isset($s[$i])) {
            $out .= $s[$i];
            ++$i;
        }

        return $out;
    }

    private static function padLeftSpaces(string $s, int $width): string
    {
        $len = 0;
        while (isset($s[$len])) {
            ++$len;
        }
        while ($len < $width) {
            $s = ' '.$s;
            ++$len;
        }

        return $s;
    }

    public static function numberFormat(
        float $number,
        int $decimals,
        string $decimalSeparator,
        string $thousandsSeparator,
        int $roundingMode = 1
    ): string {
        // Call sites that need php-src 8.3+ negative-$decimals rounding pre-round via
        // RoundMath / JitNumberFormat and pass MAX(0, $decimals) here (#27899).
        // NestedJIT of this TU mishandles negative $decimals — keep this path non-negative.
        if ($decimals < 0) {
            $decimals = 0;
        }
        $negative = 0;
        if ($number < 0.0) {
            $negative = 1;
            $number = -$number;
        }
        if (0 === $decimals) {
            if (7 === $roundingMode) {
                $intPart = (int) $number;
            } else {
                $intPart = (int) ($number + 0.5);
            }
            // php-src math.c _php_math_number_format_ex (#23980): drop sign after round-to-zero
            if (1 === $negative && 0 === $intPart) {
                $negative = 0;
            }
            $result = self::insertThousands((string) $intPart, $thousandsSeparator);

            return 1 === $negative ? self::prependMinus($result) : $result;
        }
        $scale = 1.0;
        $i = 0;
        while ($i < $decimals) {
            $scale *= 10.0;
            ++$i;
        }
        $scaled = $number * $scale;
        if (7 === $roundingMode) {
            $scaledInt = (int) $scaled;
        } else {
            $scaledInt = (int) ($scaled + 0.5);
        }
        $scaleInt = (int) $scale;
        $intPart = \intdiv($scaledInt, $scaleInt);
        $fracPart = $scaledInt % $scaleInt;
        if ($fracPart < 0) {
            $fracPart = -$fracPart;
        }
        // php-src math.c _php_math_number_format_ex (#23980): drop sign after round-to-zero
        if (1 === $negative && 0 === $intPart && 0 === $fracPart) {
            $negative = 0;
        }
        $fracDigits = self::padLeftZeros((string) $fracPart, $decimals);
        $result = self::insertThousands((string) $intPart, $thousandsSeparator);
        // NestedJIT: do not `$a .= $param.$other` — wholesale concat of separator
        // params aliases/corrupts default constant separators under thin AOT (#26991).
        $result = self::appendString($result, $decimalSeparator);
        $result = self::appendString($result, $fracDigits);

        return 1 === $negative ? self::prependMinus($result) : $result;
    }

    /** NestedJIT-safe "-".$s (#26991). */
    private static function prependMinus(string $s): string
    {
        $out = '-';
        $i = 0;
        while (isset($s[$i])) {
            $out .= $s[$i];
            ++$i;
        }

        return $out;
    }

    private static function readPackedLong(string $packed, int $packLen): ?int
    {
        // TAG_NULL → 0 (#24258); TAG_LONG (1) + 8-byte little-endian int64.
        // Index with ++ only — `$packed[$i + 1]` miscompiles under NestedJIT (#23871).
        if (1 === $packLen && self::isByte($packed[0], 0)) {
            return 0;
        }
        if ($packLen < 9 || !self::isByte($packed[0], 1)) {
            return null;
        }
        $p = 0;
        ++$p;
        $n = 0;
        $i = 0;
        while ($i < 8) {
            $n |= self::byteOrd($packed[$p]) << (8 * $i);
            ++$p;
            ++$i;
        }

        return $n;
    }

    private static function isByte(string $byte, int $code): bool
    {
        return $byte === self::byteAt($code);
    }

    private static function padLeftZeros(string $s, int $width): string
    {
        $len = 0;
        while (isset($s[$len])) {
            ++$len;
        }
        while ($len < $width) {
            $s = '0'.$s;
            ++$len;
        }

        return $s;
    }

    /** NestedJIT-safe — same shape as padLeftZeros (#26867). */
    private static function padLeftHashes(string $s, int $width): string
    {
        $len = 0;
        while (isset($s[$len])) {
            ++$len;
        }
        while ($len < $width) {
            $s = self::byteAt(35).$s;
            ++$len;
        }

        return $s;
    }

    private static function padLeftStars(string $s, int $width): string
    {
        $len = 0;
        while (isset($s[$len])) {
            ++$len;
        }
        while ($len < $width) {
            $s = '*'.$s;
            ++$len;
        }

        return $s;
    }

    private static function padLeftDashes(string $s, int $width): string
    {
        $len = 0;
        while (isset($s[$len])) {
            ++$len;
        }
        while ($len < $width) {
            $s = '-'.$s;
            ++$len;
        }

        return $s;
    }

    /**
     * Append $suffix onto $prefix one byte at a time (NestedJIT-safe, #26991).
     *
     * Thin AOT NestedJIT mishandles `$a .= $param` / `$a.$b` when $param is a
     * helper string argument that originated as a module string constant (default
     * number_format separators). Byte-wise append matches padLeftZeros style.
     */
    private static function appendString(string $prefix, string $suffix): string
    {
        $out = $prefix;
        $i = 0;
        while (isset($suffix[$i])) {
            $out .= $suffix[$i];
            ++$i;
        }

        return $out;
    }

    private static function insertThousands(string $digits, string $separator): string
    {
        $len = 0;
        while (isset($digits[$len])) {
            ++$len;
        }
        if ($len <= 3 || '' === $separator) {
            return $digits;
        }
        $firstGroup = $len % 3;
        if (0 === $firstGroup) {
            $firstGroup = 3;
        }
        $out = '';
        $i = 0;
        while ($i < $firstGroup) {
            $out .= $digits[$i];
            ++$i;
        }
        while ($i < $len) {
            $out = self::appendString($out, $separator);
            $j = 0;
            while ($j < 3 && $i < $len) {
                $out .= $digits[$i];
                ++$i;
                ++$j;
            }
        }

        return $out;
    }

    private static function byteOrd(string $byte): int
    {
        for ($code = 0; $code < 256; ++$code) {
            if ($byte === self::byteAt($code)) {
                return $code;
            }
        }

        return 0;
    }

    private static function byteAt(int $code): string
    {
        return match ($code) {
            0 => "\0", 1 => "\x01", 2 => "\x02", 3 => "\x03", 4 => "\x04", 5 => "\x05",
            6 => "\x06", 7 => "\x07", 8 => "\x08", 9 => "\x09", 10 => "\x0a", 11 => "\x0b",
            12 => "\x0c", 13 => "\x0d", 14 => "\x0e", 15 => "\x0f", 16 => "\x10", 17 => "\x11",
            18 => "\x12", 19 => "\x13", 20 => "\x14", 21 => "\x15", 22 => "\x16", 23 => "\x17",
            24 => "\x18", 25 => "\x19", 26 => "\x1a", 27 => "\x1b", 28 => "\x1c", 29 => "\x1d",
            30 => "\x1e", 31 => "\x1f", 32 => ' ', 33 => '!', 34 => '"', 35 => '#', 36 => '$',
            37 => '%', 38 => '&', 39 => "'", 40 => '(', 41 => ')', 42 => '*', 43 => '+',
            44 => ',', 45 => '-', 46 => '.', 47 => '/', 48 => '0', 49 => '1', 50 => '2',
            51 => '3', 52 => '4', 53 => '5', 54 => '6', 55 => '7', 56 => '8', 57 => '9',
            58 => ':', 59 => ';', 60 => '<', 61 => '=', 62 => '>', 63 => '?', 64 => '@',
            65 => 'A', 66 => 'B', 67 => 'C', 68 => 'D', 69 => 'E', 70 => 'F', 71 => 'G',
            72 => 'H', 73 => 'I', 74 => 'J', 75 => 'K', 76 => 'L', 77 => 'M', 78 => 'N',
            79 => 'O', 80 => 'P', 81 => 'Q', 82 => 'R', 83 => 'S', 84 => 'T', 85 => 'U',
            86 => 'V', 87 => 'W', 88 => 'X', 89 => 'Y', 90 => 'Z', 91 => '[', 92 => '\\',
            93 => ']', 94 => '^', 95 => '_', 96 => '`', 97 => 'a', 98 => 'b', 99 => 'c',
            100 => 'd', 101 => 'e', 102 => 'f', 103 => 'g', 104 => 'h', 105 => 'i', 106 => 'j',
            107 => 'k', 108 => 'l', 109 => 'm', 110 => 'n', 111 => 'o', 112 => 'p', 113 => 'q',
            114 => 'r', 115 => 's', 116 => 't', 117 => 'u', 118 => 'v', 119 => 'w', 120 => 'x',
            121 => 'y', 122 => 'z', 123 => '{', 124 => '|', 125 => '}', 126 => '~', 127 => "\x7f",
            128 => "\x80", 129 => "\x81", 130 => "\x82", 131 => "\x83", 132 => "\x84", 133 => "\x85",
            134 => "\x86", 135 => "\x87", 136 => "\x88", 137 => "\x89", 138 => "\x8a", 139 => "\x8b",
            140 => "\x8c", 141 => "\x8d", 142 => "\x8e", 143 => "\x8f", 144 => "\x90", 145 => "\x91",
            146 => "\x92", 147 => "\x93", 148 => "\x94", 149 => "\x95", 150 => "\x96", 151 => "\x97",
            152 => "\x98", 153 => "\x99", 154 => "\x9a", 155 => "\x9b", 156 => "\x9c", 157 => "\x9d",
            158 => "\x9e", 159 => "\x9f", 160 => "\xa0", 161 => "\xa1", 162 => "\xa2", 163 => "\xa3",
            164 => "\xa4", 165 => "\xa5", 166 => "\xa6", 167 => "\xa7", 168 => "\xa8", 169 => "\xa9",
            170 => "\xaa", 171 => "\xab", 172 => "\xac", 173 => "\xad", 174 => "\xae", 175 => "\xaf",
            176 => "\xb0", 177 => "\xb1", 178 => "\xb2", 179 => "\xb3", 180 => "\xb4", 181 => "\xb5",
            182 => "\xb6", 183 => "\xb7", 184 => "\xb8", 185 => "\xb9", 186 => "\xba", 187 => "\xbb",
            188 => "\xbc", 189 => "\xbd", 190 => "\xbe", 191 => "\xbf", 192 => "\xc0", 193 => "\xc1",
            194 => "\xc2", 195 => "\xc3", 196 => "\xc4", 197 => "\xc5", 198 => "\xc6", 199 => "\xc7",
            200 => "\xc8", 201 => "\xc9", 202 => "\xca", 203 => "\xcb", 204 => "\xcc", 205 => "\xcd",
            206 => "\xce", 207 => "\xcf", 208 => "\xd0", 209 => "\xd1", 210 => "\xd2", 211 => "\xd3",
            212 => "\xd4", 213 => "\xd5", 214 => "\xd6", 215 => "\xd7", 216 => "\xd8", 217 => "\xd9",
            218 => "\xda", 219 => "\xdb", 220 => "\xdc", 221 => "\xdd", 222 => "\xde", 223 => "\xdf",
            224 => "\xe0", 225 => "\xe1", 226 => "\xe2", 227 => "\xe3", 228 => "\xe4", 229 => "\xe5",
            230 => "\xe6", 231 => "\xe7", 232 => "\xe8", 233 => "\xe9", 234 => "\xea", 235 => "\xeb",
            236 => "\xec", 237 => "\xed", 238 => "\xee", 239 => "\xef", 240 => "\xf0", 241 => "\xf1",
            242 => "\xf2", 243 => "\xf3", 244 => "\xf4", 245 => "\xf5", 246 => "\xf6", 247 => "\xf7",
            248 => "\xf8", 249 => "\xf9", 250 => "\xfa", 251 => "\xfb", 252 => "\xfc", 253 => "\xfd",
            254 => "\xfe", 255 => "\xff", default => "\0",
        };
    }

    /** NestedJIT same-TU %f wire — no cross-class float cast (#31963). */
    private static function formatSprintfFWire(float $value, int $precision): string
    {
        if (\is_nan($value)) {
            return 'NaN';
        }
        if (\is_infinite($value)) {
            return $value > 0.0 ? 'INF' : '-INF';
        }
        $precision = \max(0, $precision);
        $negative = $value < 0.0;
        $abs = $negative ? -$value : $value;
        if (0.0 === $abs) {
            if ($precision > 0) {
                return ($negative ? '-' : '').'0.'.self::repeatCharWire('0', $precision);
            }

            return $negative ? '-0' : '0';
        }
        $scale = 1.0;
        $i = 0;
        while ($i < $precision) {
            $scale *= 10.0;
            ++$i;
        }
        $scaledInt = (int) ($abs * $scale + 0.5);
        $scaleInt = (int) $scale;
        if ($scaleInt <= 0) {
            $scaleInt = 1;
        }
        $intPart = intdiv($scaledInt, $scaleInt);
        $fracPart = $scaledInt % $scaleInt;
        if ($fracPart < 0) {
            $fracPart = -$fracPart;
        }
        $fracDigits = self::repeatCharWire('0', $precision);
        if ($precision > 0) {
            $fracStr = (string) $fracPart;
            $pad = $precision;
            $flen = 0;
            while (isset($fracStr[$flen])) {
                ++$flen;
            }
            $pad -= $flen;
            if ($pad > 0) {
                $fracDigits = self::repeatCharWire('0', $pad).$fracStr;
            } else {
                $fracDigits = $fracStr;
            }
        }
        $formatted = (string) $intPart;
        if ($precision > 0) {
            $formatted .= '.';
            $formatted .= $fracDigits;
        }

        return $negative ? '-'.$formatted : $formatted;
    }

    private static function repeatCharWire(string $ch, int $n): string
    {
        if ($n <= 0) {
            return '';
        }
        $out = '';
        $i = 0;
        while ($i < $n) {
            $out .= $ch;
            ++$i;
        }

        return $out;
    }
}
