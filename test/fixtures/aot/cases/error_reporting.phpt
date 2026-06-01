--TEST--
AOT: error_reporting() get/set (issue #3220)
--FILE--
<?php
$old = error_reporting(0);
echo error_reporting() === 0 ? "zero\n" : "not-zero\n";
$discard = error_reporting($old);
echo error_reporting() === $old ? "restored\n" : "restore-fail\n";
--EXPECT--
zero
restored
