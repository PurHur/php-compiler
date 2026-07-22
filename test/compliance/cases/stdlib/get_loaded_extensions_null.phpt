--TEST--
get_loaded_extensions(null) coerces to false — php-src Z_PARAM_BOOL (#18971)
--FILE--
<?php
$ext = get_loaded_extensions(null);
echo count($ext) >= 2 ? "count\n" : "no\n";
echo in_array('standard', $ext, true) ? "has_standard\n" : "no\n";
echo in_array('Zend OPcache', get_loaded_extensions(true), true) ? "zend_opcache\n" : "no\n";
echo in_array('standard', get_loaded_extensions(false), true) ? "false_has_standard\n" : "no\n";
--EXPECT--
count
has_standard
zend_opcache
false_has_standard
