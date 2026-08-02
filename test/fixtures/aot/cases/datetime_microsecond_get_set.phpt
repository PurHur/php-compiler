--TEST--
AOT: DateTime getMicrosecond/setMicrosecond (#26938)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$d = new DateTime('@0');
var_export($d->getMicrosecond()); echo "\n";
$d->setMicrosecond(123456);
var_export($d->getMicrosecond()); echo "\n";
?>
--EXPECT--
0
123456
