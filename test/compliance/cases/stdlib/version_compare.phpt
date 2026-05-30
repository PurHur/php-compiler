--TEST--
stdlib version_compare/extension_loaded/get_loaded_extensions (#3204)
--FILE--
<?php
echo function_exists('version_compare') ? "vc\n" : "no\n";
echo function_exists('extension_loaded') ? "el\n" : "no\n";
echo function_exists('get_loaded_extensions') ? "gle\n" : "no\n";
echo version_compare('8.2.0', '8.1.99', '>=') ? "ge\n" : "no\n";
echo version_compare('1.0.0', '1.0.1', '<') ? "lt\n" : "no\n";
echo extension_loaded('standard') ? "std\n" : "no\n";
echo extension_loaded('missing_extension_xyz') ? "no\n" : "unknown\n";
$ext = get_loaded_extensions();
echo in_array('standard', $ext, true) ? "has_std\n" : "no\n";
echo count($ext) >= 2 ? "count\n" : "no\n";
--EXPECT--
vc
el
gle
ge
lt
std
unknown
has_std
count
