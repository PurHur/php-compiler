--TEST--
stdlib ini_get() PERDIR/system directives — Zend CLI defaults (#13132, main/php_ini.c)
--FILE--
<?php
var_export(ini_get('realpath_cache_size'));
echo "\n";
var_export(ini_get('realpath_cache_ttl'));
echo "\n";
var_export(ini_get('post_max_size'));
echo "\n";
var_export(ini_get('upload_max_filesize'));
echo "\n";
?>
--EXPECT--
'4096K'
'120'
'8M'
'2M'
