<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_getcsv() NestedJIT helper for thin AOT (#27069, php-in-PHP).
 *
 * Single-function parse (no helper returns of array pairs — NestedJIT failed to
 * advance `$i = $parsed[1]` and hung). Indexed `$fields[$n]=` only; isset lengths.
 *
 * Semantics SSOT: {@see VmCsv::parseLine()} / php-src ext/standard/file.c php_fgetcsv.
 */
final class CsvStrGetcsvJitHelper
{
    /**
     * Strip trailing CR/LF before fgetcsv parse (php-src / {@see VmFs::fgetcsvNative}).
     */
    public static function stripLineTerminatorsArgv(string $line): string
    {
        $len = 0;
        while (isset($line[$len])) {
            ++$len;
        }
        while ($len > 0) {
            $c = $line[$len - 1];
            if ("\n" !== $c && "\r" !== $c) {
                break;
            }
            --$len;
        }
        if (0 === $len) {
            return '';
        }
        $out = '';
        $i = 0;
        while ($i < $len) {
            $out .= $line[$i];
            ++$i;
        }

        return $out;
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
        if (!isset($input[0])) {
            // NestedJIT aborts on `return [null]` — empty array signals null-row to the bridge (#27069).
            return [];
        }
        if (self::isLineTerminatorOnly($input)) {
            return [];
        }

        $delim = isset($separator[0]) ? $separator[0] : ',';
        $enc = isset($enclosure[0]) ? $enclosure[0] : '"';
        $hasEsc = isset($escape[0]);
        $esc = $hasEsc ? $escape[0] : '';

        $fields = [];
        $n = 0;
        $len = 0;
        while (isset($input[$len])) {
            ++$len;
        }
        $i = 0;
        $guard = 0;

        while ($i <= $len) {
            ++$guard;
            if ($guard > $len + 8) {
                break;
            }
            if ($i >= $len) {
                $fields[$n] = '';
                ++$n;
                break;
            }

            $j = $i;
            while ($j < $len && $input[$j] !== $delim && self::isCsvWhitespace($input[$j])) {
                ++$j;
            }

            if ($j < $len && $input[$j] === $enc) {
                $i = $j + 1;
                $field = '';
                $closed = false;
                while ($i < $len) {
                    $c = $input[$i];
                    if ($hasEsc && $esc !== $enc && $c === $esc && $i + 1 < $len) {
                        $field .= $esc.$input[$i + 1];
                        $i += 2;
                        continue;
                    }
                    if ($c === $enc) {
                        if ($i + 1 < $len && $input[$i + 1] === $enc) {
                            $field .= $enc;
                            $i += 2;
                            continue;
                        }
                        ++$i;
                        $closed = true;
                        break;
                    }
                    $field .= $c;
                    ++$i;
                }
                if ($closed) {
                    while ($i < $len && $input[$i] !== $delim) {
                        $field .= $input[$i];
                        ++$i;
                    }
                }
                if (!$closed && !isset($field[0])) {
                    $fields[$n] = "\0";
                } else {
                    $fields[$n] = $field;
                }
                ++$n;
            } else {
                $field = '';
                while ($i < $len && $input[$i] !== $delim) {
                    $field .= $input[$i];
                    ++$i;
                }
                $fields[$n] = $field;
                ++$n;
            }

            if ($i >= $len) {
                break;
            }
            if ($input[$i] === $delim) {
                ++$i;
            }
        }

        return $fields;
    }

    private static function isCsvWhitespace(string $byte): bool
    {
        return ' ' === $byte || "\t" === $byte || "\n" === $byte || "\r" === $byte || "\v" === $byte || "\f" === $byte;
    }

    private static function isLineTerminatorOnly(string $line): bool
    {
        if (!isset($line[0])) {
            return false;
        }
        $i = 0;
        while (isset($line[$i])) {
            $c = $line[$i];
            if ("\n" !== $c && "\r" !== $c) {
                return false;
            }
            ++$i;
        }

        return true;
    }
}
