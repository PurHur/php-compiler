--TEST--
AOT iconv_get/set_encoding(null) return false; omitted get returns all (#31311)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
echo var_export(is_array(iconv_get_encoding()), true), "\n";
echo var_export(iconv_get_encoding(null), true), "\n";
echo var_export(iconv_get_encoding(''), true), "\n";
echo var_export(iconv_set_encoding(null, 'UTF-8'), true), "\n";
echo var_export(iconv_get_encoding('input_encoding'), true), "\n";
?>
--EXPECT--
true
false
false
false
'UTF-8'
