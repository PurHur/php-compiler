<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_getcsv() NestedJIT helper for thin AOT (#27069, #33334, php-in-PHP).
 *
 * Length via strlen; char compares via ord() — NestedJIT isset($str[$i]) is false and
 * `$str[$i] === '"'` can fail for quote bytes (#33334 / re-#27180).
 * Outer parse cursor lives in `$pos[0]` — NestedJIT does not loop-carry a scalar
 * `$i` across the field while (#33334: quoted commas became unquoted splits).
 *
 * Semantics SSOT: {@see VmCsv::parseLine()} / php-src ext/standard/file.c php_fgetcsv.
 */
final class CsvStrGetcsvJitHelper
{
    public static function stripLineTerminatorsArgv(string $line): string
    {
        $len = \strlen($line);
        // NestedJIT: do not rebuild with `$out .= $line[$i]` — writeString/delref SIGSEGVs (#33346).
        while ($len > 0) {
            $c = \ord($line[$len - 1]);
            if (10 !== $c && 13 !== $c) {
                break;
            }
            --$len;
        }
        if (0 === $len) {
            return '';
        }
        if ($len === \strlen($line)) {
            return $line;
        }

        return \substr($line, 0, $len);
    }

    /**
     * @return list<string|null>
     */
    public static function strGetcsvArgv(
        string $input,
        string $separator,
        string $enclosure,
        string $escape,
    ): array {
        $len = \strlen($input);
        while ($len > 0) {
            $c = \ord($input[$len - 1]);
            if (10 !== $c && 13 !== $c) {
                break;
            }
            --$len;
        }
        if (0 === $len) {
            return [];
        }
        if ($len !== \strlen($input)) {
            $input = \substr($input, 0, $len);
            $len = \strlen($input);
        }

        $delimOrd = \strlen($separator) > 0 ? \ord($separator[0]) : 44; // ','
        $encOrd = \strlen($enclosure) > 0 ? \ord($enclosure[0]) : 34; // '"'
        $hasEsc = \strlen($escape) > 0;
        $escOrd = $hasEsc ? \ord($escape[0]) : 0;

        $fields = [];
        $n = 0;
        // NestedJIT (#33334): scalar `$i` is not loop-carried across the outer while —
        // cursor must live in an indexed slot (same pattern as `$fields[$n]=`).
        $pos = [];
        $pos[0] = 0;
        $guard = 0;

        while ($pos[0] <= $len) {
            ++$guard;
            if ($guard > $len + 8) {
                break;
            }
            $i = $pos[0];
            if ($i >= $len) {
                $fields[$n] = '';
                ++$n;
                break;
            }

            $j = $i;
            while ($j < $len) {
                $cj = \ord($input[$j]);
                if ($cj === $delimOrd || !(32 === $cj || 9 === $cj || 10 === $cj || 13 === $cj || 11 === $cj || 12 === $cj)) {
                    break;
                }
                ++$j;
            }

            if ($j < $len && \ord($input[$j]) === $encOrd) {
                $i = $j + 1;
                $field = '';
                $closed = false;
                while ($i < $len) {
                    $c = \ord($input[$i]);
                    if ($hasEsc && $escOrd !== $encOrd && $c === $escOrd && $i + 1 < $len) {
                        $field .= $escape[0].$input[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($c === $encOrd) {
                        if ($i + 1 < $len && \ord($input[$i + 1]) === $encOrd) {
                            $field .= $enclosure[0];
                            $i += 2;
                            continue;
                        }
                        ++$i;
                        $closed = true;
                        break;
                    }
                    $field .= $input[$i];
                    ++$i;
                }
                if ($closed) {
                    while ($i < $len && \ord($input[$i]) !== $delimOrd) {
                        $field .= $input[$i];
                        ++$i;
                    }
                }
                // Empty unclosed field → "\0" sentinel for NestedJIT (#27069).
                if (!$closed && 0 === \strlen($field)) {
                    $fields[$n] = "\0";
                } else {
                    $fields[$n] = $field;
                }
                ++$n;
            } else {
                $field = '';
                while ($i < $len && \ord($input[$i]) !== $delimOrd) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            }

            if ($i >= $len) {
                $pos[0] = $i;
                break;
            }
            if (\ord($input[$i]) === $delimOrd) {
                ++$i;
            }
            $pos[0] = $i;
        }

        return $fields;
    }
}
