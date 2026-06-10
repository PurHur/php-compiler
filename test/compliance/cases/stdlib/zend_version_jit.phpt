--TEST--
stdlib zend_version() JIT — engine version string (#3359, #5304)
--FILE--
<?php
$v = zend_version();
echo strlen($v) > 0 ? "version\n" : "no\n";
echo preg_match('/^\d+\.\d+\.\d+/', $v) ? "shape\n" : "no\n";
echo $v === zend_version() ? "stable\n" : "no\n";
--EXPECT--
version
shape
stable
