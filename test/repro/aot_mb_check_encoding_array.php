<?php
/**
 * #35365 — mb_check_encoding(array) under AOT (compileTimeArray string elems).
 * php-src: ext/mbstring/mbstring.c PHP_FUNCTION(mb_check_encoding)
 */
function enc(string $e): string { return $e; }
$ok = ['hello', 'world'];
$bad = ['ok', "\x80"];
echo 'ok=', var_export(mb_check_encoding($ok, enc('UTF-8')), true), "\n";
echo 'bad=', var_export(mb_check_encoding($bad, enc('UTF-8')), true), "\n";
echo 'lit=', var_export(mb_check_encoding(['a', 'b'], 'UTF-8'), true), "\n";
echo 'litbad=', var_export(mb_check_encoding(['a', "\x80"], 'UTF-8'), true), "\n";
echo 'empty=', var_export(mb_check_encoding([], 'UTF-8'), true), "\n";
