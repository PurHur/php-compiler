--TEST--
ext opcache_is_script_cached_in_file_cache absent on PROFILE=8.4 (#27675)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
var_export(function_exists('opcache_is_script_cached'));
echo "\n";
var_export(function_exists('opcache_is_script_cached_in_file_cache'));
echo "\n";
?>
--EXPECT--
true
false
