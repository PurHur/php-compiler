<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * CSV line parsing for str_getcsv() VM path (subset of PHP; issue #2391).
 *
 * Logic mirrors {@see lib/AOT/runtime/phpc_stream.c} phpc_parse_csv_line().
 */
final class VmCsv
{
    /**
     * @return list<string>
     */
    public static function parseLine(
        string $line,
        string $separator = ',',
        string $enclosure = '"',
        string $escape = '\\',
    ): array {
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
                    $field .= $line[++$i];
                    ++$i;
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
}
