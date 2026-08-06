<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fputcsv() field formatter for thin AOT NestedJIT (#27180, php-in-PHP).
 *
 * Single-field only — callers walk the fields HashTable in LLVM (peer JitImplode).
 * Avoids {@see CsvJitHelper::formatFieldsArgv} → HashTable::iterate / VmFputcsv /
 * VmCsv, which SIGSEGV under thin standalone NestedJIT.
 *
 * Semantics SSOT: {@see VmCsv::formatLine()} / php-src ext/standard/file.c php_fputcsv.
 */
final class CsvFputcsvJitHelper
{
    /**
     * Format one CSV cell (may add enclosure / double enclosure bytes).
     */
    public static function formatFieldArgv(
        string $field,
        string $separator,
        string $enclosure,
        string $escape,
    ): string {
        $delim = isset($separator[0]) ? $separator[0] : ',';
        $enc = isset($enclosure[0]) ? $enclosure[0] : '"';
        // php-src PHP_CSV_NO_ESCAPE — empty $escape does not treat '\' as special (#24561).
        $hasEsc = isset($escape[0]);
        $esc = $hasEsc ? $escape[0] : '';

        $needsQuotes = false;
        $i = 0;
        while (isset($field[$i])) {
            $c = $field[$i];
            if ($c === $delim || $c === $enc || ($hasEsc && $c === $esc) || "\n" === $c || "\r" === $c) {
                $needsQuotes = true;
                break;
            }
            ++$i;
        }
        if (!$needsQuotes) {
            return $field;
        }

        $out = $enc;
        $i = 0;
        while (isset($field[$i])) {
            $c = $field[$i];
            if ($c === $enc) {
                // php-src — only enclosure is doubled inside quotes.
                $out .= $enc.$enc;
            } else {
                $out .= $c;
            }
            ++$i;
        }

        return $out.$enc;
    }
}
