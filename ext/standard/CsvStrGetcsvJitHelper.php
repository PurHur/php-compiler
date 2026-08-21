<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * str_getcsv() NestedJIT helper for thin AOT (#27069, php-in-PHP).
 *
 * Single-function parse (no helper returns of array pairs — NestedJIT failed to
 * advance `$i = $parsed[1]` and hung). Indexed `$fields[$n]=` only; isset lengths.
 *
 * NestedJIT cannot lower an in-loop `$i += 2; continue` escape consume after an
 * unquoted field (#33334 / re-#27180): enclosure detection then fails and
 * `a,"b,c",d` splits as `a` / `"b` / `c"`. Factor escape append into
 * {@see appendEscapedPairArgv}. Avoid an in-function `$j` whitespace peek beside
 * that escape CFG — same miscompile; leading WS-before-enclosure is accepted as
 * part of an unquoted field (php-src peeks; rare for fgetcsv line bodies).
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
     * NestedJIT-safe escape pair append (#33334) — keep `$i += 2; continue` out of
     * the enclosed-field loop body (inline form breaks later enclosure detects).
     */
    public static function appendEscapedPairArgv(string $input, int $i, string $esc): string
    {
        return $esc.$input[$i + 1];
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
        // php-src / VmCsv::parseLine — strip trailing CR/LF before parse (#28994).
        // Inlined from stripLineTerminatorsArgv (no NestedJIT cross-method return).
        $len = 0;
        while (isset($input[$len])) {
            ++$len;
        }
        while ($len > 0) {
            $c = $input[$len - 1];
            if ("\n" !== $c && "\r" !== $c) {
                break;
            }
            --$len;
        }
        if (0 === $len) {
            // NestedJIT aborts on `return [null]` — empty array signals null-row to the bridge (#27069).
            // Also covers terminator-only rows after strip (#10623).
            return [];
        }
        $trimmed = '';
        $ti = 0;
        while ($ti < $len) {
            $trimmed .= $input[$ti];
            ++$ti;
        }
        $input = $trimmed;

        $delim = isset($separator[0]) ? $separator[0] : ',';
        $enc = isset($enclosure[0]) ? $enclosure[0] : '"';
        $useEsc = false;
        $esc = '';
        if (isset($escape[0])) {
            $esc = $escape[0];
            // php-src: escape === enclosure → proprietary escape disabled.
            if ($esc !== $enc) {
                $useEsc = true;
            }
        }

        $fields = [];
        $n = 0;
        // $len already counted after terminator strip.
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

            // No `$j` whitespace peek here — NestedJIT miscompiles it beside escape CFG (#33334).
            if ($input[$i] === $enc) {
                ++$i;
                $field = '';
                $closed = false;
                while ($i < $len) {
                    $c = $input[$i];
                    if ($useEsc && $c === $esc && $i + 1 < $len) {
                        $field .= self::appendEscapedPairArgv($input, $i, $esc);
                        ++$i;
                        ++$i;
                        continue;
                    }
                    if ($c === $enc) {
                        if ($i + 1 < $len && $input[$i + 1] === $enc) {
                            $field .= $enc;
                            ++$i;
                            ++$i;
                            continue;
                        }
                        ++$i;
                        $closed = true;
                        break;
                    }
                    $field .= $c;
                    ++$i;
                }
                // php-src quit_loop_2/3 — bytes after closing enclosure until delimiter.
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
}
