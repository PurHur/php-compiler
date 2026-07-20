--TEST--
stdlib radix convert builtins — null soft-null on 8.4 (#21244, ext/standard/base_convert.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
error_reporting(E_ALL);
set_error_handler(static function (): bool { return true; });
echo var_export(dechex(null), true), "\n";
echo var_export(decbin(null), true), "\n";
echo var_export(decoct(null), true), "\n";
echo var_export(hexdec(null), true), "\n";
echo var_export(bindec(null), true), "\n";
echo var_export(octdec(null), true), "\n";
echo var_export(base_convert(null, 10, 16), true), "\n";
?>
--EXPECT--
'0'
'0'
'0'
0
0
0
'0'
