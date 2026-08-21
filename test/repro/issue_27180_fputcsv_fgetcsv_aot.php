<?php

/**
 * Issue #33334 / re-#27180 — AOT fputcsv must match Zend (no SIGSEGV).
 *
 * NestedJIT CsvFputcsvJitHelper previously used isset($str[$i]), which is false /
 * unsafe under thin AOT and SIGSEGV'd formatFieldArgv. Do not also call str_getcsv
 * in this binary — NestedJIT of both CSV helpers in one module is heap-flaky.
 *
 * php-src: ext/standard/file.c — php_fputcsv()
 */
$f = fopen('php://memory', 'r+');
$n = fputcsv($f, ['a', 'b,c', 'd'], ',', '"', '\\');
rewind($f);
$line = stream_get_contents($f);
echo 'n='.$n."\n";
echo 'line='.$line;
echo 'line_len='.strlen($line)."\n";
