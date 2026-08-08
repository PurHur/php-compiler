<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * CSV line parsing for str_getcsv() VM path (subset of PHP; issue #2391).
 *
 * Canonical CSV parser for VM + JIT/AOT ({@see CsvJitHelper}, StringStrGetcsv, #9444).
 */
final class VmCsv
{
    /**
     * @return list<string|null>
     */
    public static function parseLine(
        string $line,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '\\',
    ): array {
        // php-src ext/standard/file.c — zero-length line is one NULL field (#4922).
        if ('' === $line) {
            return [null];
        }
        // php-src ext/standard/file.c — line-terminator-only rows yield one NULL field (#10623).
        if (self::isLineTerminatorOnly($line)) {
            return [null];
        }

        $delim = '' === $separator ? ',' : $separator[0];
        $enc = '' === $enclosure ? '"' : $enclosure[0];
        // php-src file.h PHP_CSV_NO_ESCAPE — empty $escape disables proprietary escaping (#24561 / #4164).
        $esc = '' === $escape ? null : $escape[0];

        $fields = [];
        $len = \strlen($line);
        $i = 0;

        while ($i <= $len) {
            $field = '';
            if ($i < $len) {
                $field = self::parseField($line, $i, $len, $delim, $enc, $esc);
            }
            $fields[] = $field;
            if ($i >= $len) {
                break;
            }
            if ($line[$i] === $delim) {
                ++$i;
            }
        }

        return $fields;
    }

    /**
     * Parse one CSV field; advances $offset past the field (php-src ext/standard/file.c 2A/2B).
     *
     * @param string|null $esc escape byte, or null for PHP_CSV_NO_ESCAPE
     */
    private static function parseField(
        string $line,
        int &$offset,
        int $len,
        string $delim,
        string $enc,
        ?string $esc,
    ): string {
        $i = $offset;
        $j = $i;
        while ($j < $len && $line[$j] !== $delim && self::isCsvWhitespace($line[$j])) {
            ++$j;
        }
        if ($j < $len && $line[$j] === $enc) {
            $i = $j + 1;
            $field = '';
            $closed = false;
            while ($i < $len) {
                $c = $line[$i];
                // Escape only when enabled and distinct from enclosure (php-src state 1).
                if (null !== $esc && $esc !== $enc && $c === $esc && $i + 1 < $len) {
                    $field .= $esc.$line[$i + 1];
                    $i += 2;
                    continue;
                }
                if ($c === $enc) {
                    if ($i + 1 < $len && $line[$i + 1] === $enc) {
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
            // php-src quit_loop_2/3 — bytes after the closing enclosure until the delimiter
            // remain part of the same field (e.g. `"ab"c,d` → `abc`).
            if ($closed) {
                while ($i < $len && $line[$i] !== $delim) {
                    $field .= $line[$i];
                    ++$i;
                }
            }
            $offset = $i;
            // php-src ext/standard/file.c PHP 8.2 — unterminated empty enclosure yields NUL (#18592).
            if (!$closed && '' === $field) {
                return "\0";
            }

            return $field;
        }

        $field = '';
        while ($i < $len && $line[$i] !== $delim) {
            $field .= $line[$i];
            ++$i;
        }
        $offset = $i;

        return $field;
    }

    private static function isCsvWhitespace(string $byte): bool
    {
        return ' ' === $byte || "\t" === $byte || "\n" === $byte || "\r" === $byte || "\v" === $byte || "\f" === $byte;
    }

    /** @return bool true when every byte is \\r or \\n (non-empty). */
    private static function isLineTerminatorOnly(string $line): bool
    {
        $len = \strlen($line);
        if (0 === $len) {
            return false;
        }
        for ($i = 0; $i < $len; ++$i) {
            $c = $line[$i];
            if ("\n" !== $c && "\r" !== $c) {
                return false;
            }
        }

        return true;
    }

    /**
     * Format one CSV row for fputcsv() (php-src ext/standard/file.c; #5243).
     *
     * @param list<string> $fields
     */
    public static function formatLine(
        array $fields,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '\\',
    ): string {
        $delim = '' === $separator ? ',' : $separator[0];
        $enc = '' === $enclosure ? '"' : $enclosure[0];
        // php-src PHP_CSV_NO_ESCAPE — empty $escape does not treat '\' as special (#24561).
        $esc = '' === $escape ? null : $escape[0];

        $parts = [];
        foreach ($fields as $field) {
            $parts[] = self::formatField($field, $delim, $enc, $esc);
        }

        return \implode($delim, $parts);
    }

    /** @param string|null $esc escape byte, or null for PHP_CSV_NO_ESCAPE */
    private static function formatField(string $field, string $delim, string $enc, ?string $esc): string
    {
        $needsQuotes = false;
        for ($i = 0, $len = \strlen($field); $i < $len; ++$i) {
            $c = $field[$i];
            // php-src FPUTCSV_FLD_CHK: delim, enclosure, escape, CR/LF, space, tab (#29058).
            if ($c === $delim || $c === $enc || (null !== $esc && $c === $esc)
                || "\n" === $c || "\r" === $c || ' ' === $c || "\t" === $c) {
                $needsQuotes = true;
                break;
            }
        }
        if (!$needsQuotes) {
            return $field;
        }

        $out = $enc;
        for ($i = 0, $len = \strlen($field); $i < $len; ++$i) {
            $c = $field[$i];
            if ($c === $enc) {
                // php-src ext/standard/file.c — only enclosure is doubled inside quotes.
                $out .= $enc.$enc;
                continue;
            }
            $out .= $c;
        }

        return $out.$enc;
    }
}
