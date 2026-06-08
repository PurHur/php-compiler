--TEST--
stdlib zend_version() returns engine version (#3359, #5304)
--FILE--
<?php
echo function_exists('zend_version') ? "fn\n" : "no\n";
$v = zend_version();
echo strlen($v) > 0 ? "version\n" : "no\n";
echo preg_match('/^\d+\.\d+\.\d+/', $v) ? "shape\n" : "no\n";
--EXPECT--
fn
version
shape
