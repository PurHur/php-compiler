<?php
// #29058 — php-src php_fputcsv encloses fields containing space or tab.
$f = fopen('php://memory', 'r+');
fputcsv($f, ['a b', "a\tb", 'ok'], ',', '"', '');
rewind($f);
echo stream_get_contents($f);
