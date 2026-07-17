<?php
/**
 * Issue #20015 — mb_parse_str() UTF-8 query parse (php-src ext/mbstring/mbstring.c).
 * Avoid var_export() — AOT var_export of mixed values can segfault in this build.
 */
echo 'exists=', function_exists('mb_parse_str') ? '1' : '0', "\n";
$r = [];
$ok = mb_parse_str('a=1&b=%E3%81%82', $r);
echo 'ok=', $ok ? 'true' : 'false', "\n";
echo 'a=', $r['a'], "\n";
echo 'b=', $r['b'], "\n";
$empty = ['keep' => 1];
$okEmpty = mb_parse_str('', $empty);
echo 'empty_ok=', $okEmpty ? 'true' : 'false', "\n";
echo 'empty_count=', count($empty), "\n";
