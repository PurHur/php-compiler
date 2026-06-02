--TEST--
stdlib error_reporting() get/set (issue #3220)
--FILE--
<?php
echo function_exists('error_reporting') ? "exists\n" : "missing\n";
$old = error_reporting(0);
echo $old === 32767 ? "old-level\n" : "old-bad\n";
echo error_reporting() === 0 ? "zero\n" : "not-zero\n";
$discard = error_reporting($old);
echo error_reporting() === $old ? "restored\n" : "restore-fail\n";
$unchanged = error_reporting(null);
echo $unchanged === $old ? "null-unchanged\n" : "null-bad\n";
--EXPECT--
exists
old-level
zero
restored
null-unchanged
