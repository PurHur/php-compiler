--TEST--
AOT: nextafter() phantom absent under PROFILE≥8.4 (#28565)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo function_exists('nextafter') ? "fn-fail\n" : "fn-ok\n";
echo function_exists('fpow') ? "fpow-ok\n" : "fpow-fail\n";
?>
--EXPECT--
fn-ok
fpow-ok
