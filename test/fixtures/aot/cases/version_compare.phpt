--TEST--
AOT version_compare/extension_loaded/get_loaded_extensions (#3204)
--FILE--
<?php
echo function_exists('version_compare') ? "vc\n" : "no\n";
echo version_compare('8.2.0', '8.1.99', '>=') ? "ge\n" : "no\n";
echo extension_loaded('standard') ? "std\n" : "no\n";
$ext = get_loaded_extensions();
echo in_array('standard', $ext, true) ? "has_std\n" : "no\n";
--EXPECT--
vc
ge
std
has_std
