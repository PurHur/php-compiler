--TEST--
stdlib fputcsv() encloses fields with space/tab like php-src (#29058, file.c)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
fputcsv($f, ['a b', "a\tb", 'ok'], ',', '"', '');
rewind($f);
echo stream_get_contents($f);
--EXPECT--
"a b","a	b",ok
