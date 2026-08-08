<?php
// Repro #29024 — PCRE \x{N} brace hex escapes (ext/pcre/php_pcre.c, VmPregEngine)
foreach (['/\x{41}/', '/\x{41}/u', '/\x41/'] as $pat) {
    $r = @preg_match($pat, 'A');
    echo $pat, ' => ';
    var_export($r);
    echo ' ', preg_last_error_msg(), "\n";
}
$r = @preg_match('/\x{ff}/u', "\u{ff}");
echo '/\\x{ff}/u => ';
var_export($r);
echo ' ', preg_last_error_msg(), "\n";
$r = @preg_replace('/\x{41}/u', 'B', 'A');
echo 'replace=';
var_export($r);
echo ' ', preg_last_error_msg(), "\n";
