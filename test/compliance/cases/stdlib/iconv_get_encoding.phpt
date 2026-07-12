--TEST--
stdlib iconv_get_encoding()/iconv_set_encoding() — encoding settings round-trip (#6364, ext/iconv/iconv.c)
--FILE--
<?php
var_export(is_array(iconv_get_encoding()));
echo "\n";
var_export(iconv_get_encoding('input_encoding'));
echo "\n";
var_export(iconv_set_encoding('input_encoding', 'ISO-8859-1'));
echo "\n";
var_export(iconv_get_encoding('input_encoding'));
echo "\n";
var_export(iconv_set_encoding('all', 'UTF-8'));
echo "\n";
var_export(iconv_get_encoding('bogus'));
echo "\n";
?>
--EXPECT--
true
'UTF-8'
true
'ISO-8859-1'
false
false
