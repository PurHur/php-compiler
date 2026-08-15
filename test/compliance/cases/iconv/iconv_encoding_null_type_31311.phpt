--TEST--
iconv_get/set_encoding(null) soft DEP + false; omitted get returns all (#31311, ext/iconv/iconv.c)
--FILE--
<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
echo var_export(is_array(iconv_get_encoding()), true), "\n";
echo var_export(iconv_get_encoding(null), true), "\n";
echo var_export(iconv_get_encoding(''), true), "\n";
echo var_export(iconv_set_encoding(null, 'UTF-8'), true), "\n";
echo var_export(iconv_get_encoding('input_encoding'), true), "\n";
?>
--EXPECTF--
true

Deprecated: iconv_get_encoding(): Passing null to parameter #1 ($type) of type string is deprecated in %s on line %d
false
false

Deprecated: iconv_set_encoding(): Passing null to parameter #1 ($type) of type string is deprecated in %s on line %d
false
'UTF-8'
