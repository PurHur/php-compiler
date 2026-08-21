<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * fputcsv() field formatter for thin AOT NestedJIT (#27180, php-in-PHP).
 *
 * Single-field only — callers walk the fields HashTable in LLVM (peer JitImplode).
 * Avoids {@see CsvJitHelper::formatFieldsArgv} → HashTable::iterate / VmFputcsv /
 * VmCsv, which SIGSEGV under thin standalone NestedJIT.
 * Loops use strlen (not isset($str[$i])) — NestedJIT isset(string offset) is wrong (#33334).
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
        // Prefer strlen + index over isset($str[$i]) — NestedJIT thin AOT treats
        // isset(string offset) as false / can SIGSEGV (#33334, re-#27180).
        $delim = '' !== $separator ? $separator[0] : ',';
        $enc = '' !== $enclosure ? $enclosure[0] : '"';
        // php-src PHP_CSV_NO_ESCAPE — empty $escape does not treat '\' as special (#24561).
        $hasEsc = '' !== $escape;
        $esc = $hasEsc ? $escape[0] : '';

        $needsQuotes = false;
        $len = \strlen($field);
        $i = 0;
        while ($i < $len) {
            $c = $field[$i];
            // php-src FPUTCSV_FLD_CHK: delim, enclosure, escape, CR/LF, space, tab (#29058).
            if ($c === $delim || $c === $enc || ($hasEsc && $c === $esc)
                || "\n" === $c || "\r" === $c || ' ' === $c || "\t" === $c) {
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
        while ($i < $len) {
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
