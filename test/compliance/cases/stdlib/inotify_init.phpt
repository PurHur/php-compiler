--TEST--
inotify_init() exists when inotify FFI is available (#6410)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) {
    die('skip FFI required');
}
$disable = getenv('PHP_COMPILER_DISABLE_FFI');
if (false !== $disable && '' !== $disable && '0' !== $disable && 'false' !== strtolower((string) $disable)) {
    die('skip FFI disabled');
}
?>
--FILE--
<?php
var_export(function_exists('inotify_init'));
var_export(function_exists('inotify_add_watch'));
var_export(function_exists('inotify_read'));
echo "\n";
?>
--EXPECT--
true
true
true
