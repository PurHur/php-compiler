--TEST--
stdlib nextafter() phantom on PROFILE≥8.4 — absent from php-src (#28565)
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
