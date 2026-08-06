<?php

/**
 * Issue #27180 — AOT fputcsv/fgetcsv memory-stream spot-check (php-src-strict).
 *
 * Thin AOT previously failed compile (StreamIoJitHelper::ftellArgv) or SIGSEGV'd
 * in CsvJitHelper::formatFieldsArgv NestedJIT. Done-when: stdout matches Zend.
 *
 * Avoid json_encode(stream_get_contents()) and count($row) under thin AOT — those
 * hit separate NestedJIT/string and HashTable-count gaps; this guard covers the
 * CSV write/parse path named in the issue.
 */
$f = fopen('php://memory', 'r+');
$n = fputcsv($f, ['a', 'b,c', 'd'], ',', '"', '\\');
rewind($f);
$line = stream_get_contents($f);
echo 'n='.$n."\n";
echo 'line='.$line;
echo 'line_len='.strlen($line)."\n";

file_put_contents('/tmp/issue_27180_fget.csv', "a,\"b,c\",d\n");
$g = fopen('/tmp/issue_27180_fget.csv', 'r');
$row = fgetcsv($g);
echo 'row0='.$row[0].' row1='.$row[1].' row2='.$row[2]."\n";
