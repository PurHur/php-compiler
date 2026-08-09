--TEST--
range() multibyte string bounds emit E_WARNING under PROFILE=8.4 (#29203, ext/standard/array.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
$msgs = [];
set_error_handler(function ($n, $m) use (&$msgs) {
    $msgs[] = $m;
    return true;
});
$r = range('あ', 'う');
restore_error_handler();
echo 'count=', count($r), "\n";
echo 'first_hex=', bin2hex($r[0] ?? ''), "\n";
echo implode("\n", $msgs), "\n";
$msgs = [];
set_error_handler(function ($n, $m) use (&$msgs) {
    $msgs[] = $m;
    return true;
});
$r2 = range('aa', 'c');
restore_error_handler();
echo implode(',', $r2), "\n";
echo implode("\n", $msgs), "\n";
--EXPECT--
count=1
first_hex=e3
range(): Argument #1 ($start) must be a single byte, subsequent bytes are ignored
range(): Argument #2 ($end) must be a single byte, subsequent bytes are ignored
a,b,c
range(): Argument #1 ($start) must be a single byte, subsequent bytes are ignored
