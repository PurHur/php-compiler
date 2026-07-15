--TEST--
stdlib non-strict caller — Z_PARAM_STR null coerces to empty string (#19114, ext/standard/string.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo nl2br(null), "\n";
echo str_rot13(null), "\n";
echo bin2hex(null), "\n";
echo hex2bin(null), "\n";
echo crc32(null), "\n";
?>
--EXPECT--




0
