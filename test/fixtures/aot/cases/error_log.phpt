--TEST--
AOT: error_log() type 3 file append returns true (#3380)
--SKIPIF--
<?php
if (!function_exists('error_log')) {
    die('skip error_log unavailable');
}
?>
--FILE--
<?php
$ok = error_log('aot file', 3, '/tmp/phpc-aot-error-log.log');
echo $ok ? "true\n" : "false\n";
echo "called\n";
?>
--EXPECT--
true
called
