--TEST--
AOT: preg_* \\x{N} brace hex escapes (#29024, ext/pcre/php_pcre.c)
--FILE--
<?php
foreach (['/\x{41}/', '/\x{41}/u', '/\x41/'] as $pat) {
    $r = @preg_match($pat, 'A');
    echo $pat, ' => ', var_export($r, true), ' ', preg_last_error_msg(), "\n";
}
$r = @preg_match('/\x{ff}/u', "\u{ff}");
echo '/\\x{ff}/u => ', var_export($r, true), ' ', preg_last_error_msg(), "\n";
$r = @preg_replace('/\x{41}/u', 'B', 'A');
echo 'replace=', var_export($r, true), ' ', preg_last_error_msg(), "\n";
--EXPECT--
/\x{41}/ => 1 No error
/\x{41}/u => 1 No error
/\x41/ => 1 No error
/\x{ff}/u => 1 No error
replace='B' No error
