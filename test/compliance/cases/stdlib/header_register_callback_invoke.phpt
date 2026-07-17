--TEST--
stdlib header_register_callback() — closure runs before body output (#3492, ext/standard/head.c)
--FILE--
<?php
function hrc_str_cb(): void {
    $GLOBALS['hrc_str'] = 1;
}
$ok = false;
header_register_callback(function () use (&$ok): void {
    $ok = true;
});
header_register_callback('hrc_str_cb');
echo "body\n";
echo $ok ? "callback\n" : "missing\n";
echo !empty($GLOBALS['hrc_str']) ? "str_ok\n" : "str_missing\n";
--EXPECT--
body
callback
str_ok
