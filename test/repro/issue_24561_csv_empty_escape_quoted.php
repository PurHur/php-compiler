<?php

/**
 * #24561 — empty $escape disables proprietary escaping; quoted \" is not an escape.
 * php-src ext/standard/file.c PHP_CSV_NO_ESCAPE + quit_loop_2/3 after-close append.
 */
$s = "a,\"b\\\"c\",d";
echo 'str=', json_encode(str_getcsv($s, ',', '"', '')), "\n";

$f = fopen('php://memory', 'r+');
fwrite($f, $s."\n");
rewind($f);
echo 'fget=', json_encode(fgetcsv($f, 0, ',', '"', '')), "\n";

// Unquoted empty-escape stays two fields (#4164).
echo 'unq=', json_encode(str_getcsv('a\\,b', ',', '"', '')), "\n";

// After closing enclosure, trailing bytes until delimiter stay in-field (php-src 2A).
echo 'trail=', json_encode(str_getcsv('"ab"c,d', ',', '"', '\\')), "\n";
