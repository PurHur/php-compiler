--TEST--
ext opcache_is_script_cached_in_file_cache on PROFILE=8.5 (#27675)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
error_reporting(E_ALL & ~E_NOTICE);
var_export(function_exists('opcache_is_script_cached'));
echo "\n";
var_export(function_exists('opcache_is_script_cached_in_file_cache'));
echo "\n";
$f = sys_get_temp_dir() . '/opc_file_cache_compliance_' . getmypid() . '.php';
file_put_contents($f, "<?php return 1;\n");
var_export(opcache_is_script_cached_in_file_cache($f));
echo "\n";
@unlink($f);
?>
--EXPECT--
true
true
false
