--TEST--
stdlib zlib_get_coding_type() registered — CLI returns false without compression (#12280, ext/zlib/zlib.c)
--FILE--
<?php
declare(strict_types=1);
var_export(function_exists('zlib_get_coding_type'));
echo "\n";
var_export(zlib_get_coding_type());
echo "\n";
?>
--EXPECT--
true
false
