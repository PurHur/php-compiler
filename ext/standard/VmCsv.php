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
        $esc = '' === $escape ? '\\' : $escape[0];

        $fields = [];
        $field = '';
        $inQuotes = false;
        $len = \strlen($line);
        $i = 0;

        while ($i <= $len) {
            $c = $i < $len ? $line[$i] : "\0";

            if ($inQuotes) {
                if ("\0" === $c) {
                    break;
                }
                if ($c === $esc && $i + 1 < $len) {
                    // php-src ext/standard/file.c — state 1 copies escape + next byte inside enclosure.
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
                    $inQuotes = false;
                    ++$i;
                    continue;
                }
                $field .= $c;
                ++$i;
                continue;
            }

            if ("\0" === $c || $c === $delim) {
                $fields[] = $field;
                $field = '';
                if ("\0" === $c) {
                    break;
                }
                ++$i;
                continue;
            }

            if ($c === $enc) {
                $inQuotes = true;
                ++$i;
                continue;
            }

            $field .= $c;
            ++$i;
        }

        return $fields;
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
        $esc = '' === $escape ? '\\' : $escape[0];

        $parts = [];
        foreach ($fields as $field) {
            $parts[] = self::formatField($field, $delim, $enc, $esc);
        }

        return \implode($delim, $parts);
    }

    private static function formatField(string $field, string $delim, string $enc, string $esc): string
    {
        $needsQuotes = false;
        for ($i = 0, $len = \strlen($field); $i < $len; ++$i) {
            $c = $field[$i];
            if ($c === $delim || $c === $enc || $c === $esc || "\n" === $c || "\r" === $c) {
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
                $out .= $enc.$enc;
                continue;
            }
            if ($c === $esc) {
                $out .= $esc.$esc;
                continue;
            }
            $out .= $c;
        }

        return $out.$enc;
    }
}
