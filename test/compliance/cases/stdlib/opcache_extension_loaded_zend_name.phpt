--TEST--
extension_loaded Zend OPcache not bare opcache (#24993, ext/opcache)
--FILE--
<?php
var_export(extension_loaded('opcache'));
echo "\n";
var_export(extension_loaded('Zend OPcache'));
echo "\n";
var_export(function_exists('opcache_get_status'));
echo "\n";
var_export(false !== get_extension_funcs('Zend OPcache'));
echo "\n";
var_export(false === get_extension_funcs('opcache'));
echo "\n";
--EXPECT--
false
true
true
true
true
